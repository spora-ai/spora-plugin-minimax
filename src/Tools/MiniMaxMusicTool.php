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
 * Song-making operations for MiniMax, consolidated into one tool:
 * `compose`, `write_lyrics`, `edit_lyrics`. Returns the upstream audio URL
 * (24h expiry) when `output_format=url`; hex otherwise.
 */
#[Tool(
    name: 'music',
    description: 'Generate music (instrumental or with lyrics) or write/edit song lyrics. The "action" argument selects the operation.',
    displayName: 'MiniMax Music',
    category: 'generation',
    icon: 'music',
)]
#[ToolOperation(name: 'compose', description: 'Generate music (instrumental or with lyrics)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'write_lyrics', description: 'Write a full song of lyrics from a topic or style description', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'edit_lyrics', description: 'Rewrite existing lyrics according to a prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
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
    description: 'Music model id (default: music-3.0). Applies to `compose`; the lyrics endpoint has no model parameter. Per https://platform.minimax.io/docs/guides/models-intro, `music-3.0` is the current default; `music-2.6` and `music-cover` are also accepted.',
    default: 'music-3.0',
)]
#[ToolSetting(
    key: 'http_timeout_seconds',
    label: 'Compose HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the `compose` operation. Default 180 seconds (compose can take 60-180 s on slow networks).',
    default: '180',
)]
#[ToolSetting(
    key: 'http_timeout_seconds_lyrics',
    label: 'Lyrics HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for `write_lyrics` / `edit_lyrics`. Default 30 seconds.',
    default: '30',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Style / mood description (max 2000 characters). For `compose`: optional when `lyrics` is provided. For `write_lyrics`: topic or style. For `edit_lyrics`: rewrite instruction (required).',
    required: ['edit_lyrics'],
    maximum: 2000,
)]
#[ToolParameter(
    name: 'lyrics',
    type: 'string',
    description: 'Lyrics to sing or edit (1-3500 characters). Omit for instrumental music (compose). Required for `edit_lyrics`.',
    required: ['edit_lyrics'],
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
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "midnight-lofi"). The correct file extension is appended automatically. When omitted, a speaking name is generated from the prompt.',
    required: false,
    maximum: 120,
)]
final class MiniMaxMusicTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER              = 'music';
    protected const DEFAULT_MODEL         = 'music-3.0';
    protected const QUALIFIED_NAME        = 'minimax:music';
    protected const TIMEOUT_SECONDS       = 30; // overridden per-op
    protected const TOOL_LABEL            = ''; // unused — dispatch via execute()
    protected const TIMEOUT_SECONDS_COMPOSE = 180;
    protected const TIMEOUT_SECONDS_LYRICS  = 30;
    protected const AUDIO_MIME            = 'audio/mpeg';

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
        $this->attachMusicMediaArchive($mediaArchive);
    }

    private function attachMusicMediaArchive(?\Spora\Services\MediaArchive\MediaArchiveService $archive): void
    {
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    /**
     * Multi-operation tool: dispatch on the `action` argument.
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
     * Base-class hooks unused by this multi-operation tool — dispatch
     * happens in {@see execute()}. Throwing surfaces accidental calls.
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

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $composeTimeout = $this->resolveTimeout('http_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS_COMPOSE);
        $response = $client->postJson(
            '/v1/music_generation',
            $this->buildComposeBody($ctx->settings, $prompt, $lyrics, $outputFormat),
            timeoutSeconds: $composeTimeout,
        );

        $data     = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rawAudio = is_string($data['audio'] ?? null) ? $data['audio'] : null;

        // MiniMax's music_generation endpoint returns the audio in a single
        // field `data.audio` regardless of `output_format`:
        //   - `output_format=url` (default) → `data.audio` is a CDN URL string.
        //   - `output_format=hex`           → `data.audio` is a hex-encoded MP3 blob.
        // There is NO separate `data.audio_url` field for music (unlike the
        // speech API, which does have both). Dispatch on the URL prefix so a
        // payload that happens to start with "https" still routes correctly
        // regardless of which `output_format` the caller asked for.
        if ($rawAudio !== null && (str_starts_with($rawAudio, 'http://') || str_starts_with($rawAudio, 'https://'))) {
            $audioUrl = $rawAudio;
            $hexAudio = null;
        } else {
            $audioUrl = null;
            $hexAudio = $rawAudio;
        }

        if ($hexAudio === null && $audioUrl === null) {
            $this->support->logFailure($ctx, $response, 'No audio in response');
            return new ToolResult(false, 'MiniMax returned no audio data.');
        }

        $this->support->logSuccess($ctx, $response);

        $resolved = $this->resolveComposePlayback($audioUrl, $hexAudio);
        if ($resolved === null) {
            return new ToolResult(false, 'MiniMax returned audio in an unsupported format.');
        }
        [$url, $assetMode] = $resolved;

        $archiveAsset = $this->ingestIntoMediaArchive($ctx, $audioUrl, $hexAudio, $prompt, $arguments);
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
                'MiniMaxMusicTool produced a data: URI; LocalAssetStore / MediaArchive wiring is missing from the DI container.',
            );
        }

        $promptSummary = $prompt !== '' ? "prompt: \"{$prompt}\"" : 'instrumental';

        $renderInstruction = str_starts_with($url, '/api/v1/assets/')
            ? "Echo the `<audio>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp3` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`."
            : "Echo the `<audio>` element above verbatim — its `src` is the upstream MiniMax CDN URL (~24 h expiry); the Media Archive plugin isn't installed or this file was rejected, so the URL isn't rewritten to a long-lived `/api/v1/assets/...` path. Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.";

        return new ToolResult(true, "Generated music ({$promptSummary}).\n\n"
            . MediaEmbed::audioFromUrl($url)
            . "\n\n" . $renderInstruction, [
                'audio_url'  => $audioUrl,
                'asset_url'  => $url,
                'asset_mode' => $assetMode,
            ]);
    }

    /**
     * Returns `[url, mode]` for the playback URL, or null if the
     * payload is neither a usable URL nor a valid hex blob.
     *
     * @return array{0: string, 1: string|null}|null
     */
    private function resolveComposePlayback(?string $audioUrl, ?string $hexAudio): ?array
    {
        if ($audioUrl !== null && $audioUrl !== '') {
            return [$audioUrl, null];
        }
        if ($hexAudio !== '' && $hexAudio !== null && strlen($hexAudio) % 2 === 0) {
            return $this->embedComposeHex($hexAudio);
        }
        return null;
    }

    /**
     * Prefers {@see LocalAssetStore} so the chat UI never sees a
     * `data:` URI (the chat sanitizer truncates long base64).
     *
     * @return array{0: string, 1: string}
     */
    private function embedComposeHex(string $hex): array
    {
        if ($this->localAssetStore !== null) {
            $bytes = hex2bin($hex);
            if ($bytes === false || $bytes === '') {
                throw new InvalidArgumentException('Hex payload decoded to empty bytes.');
            }
            $ref = $this->localAssetStore->store($bytes, mime: self::AUDIO_MIME, filename: 'song.mp3');
            return [$ref->url, $ref->mode];
        }
        return $this->embedHex($hex, self::AUDIO_MIME, 'song.mp3');
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return \Spora\Models\MediaAsset|null  null when ingest was skipped
     *                                         (no payload) or failed.
     */
    private function ingestIntoMediaArchive(
        MiniMaxToolContext $ctx,
        ?string $audioUrl,
        ?string $hexAudio,
        string $prompt,
        array $arguments,
    ): ?\Spora\Models\MediaAsset {
        if ($audioUrl === null && ($hexAudio === null || $hexAudio === '')) {
            return null;
        }

        try {
            return $this->mediaArchive()->ingest(new MediaIngestRequest(
                url: $audioUrl,
                hex: $audioUrl === null ? $hexAudio : null,
                agentId: $ctx->agentId,
                pluginSlug: 'minimax',
                toolName: 'music',
                mime: self::AUDIO_MIME,
                prompt: $prompt,
                filename: self::resolveFilename(
                    isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                    $prompt,
                    'minimax-music',
                    'mp3',
                ),
            ));
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (music)', [
                'exception' => $e,
            ]);
            return null;
        }
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

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $lyricsTimeout = $this->resolveTimeout('http_timeout_seconds_lyrics', $ctx->settings, static::TIMEOUT_SECONDS_LYRICS);
        $response = $client->postJson(
            '/v1/lyrics_generation',
            $this->buildLyricsBody($mode, $prompt, $lyrics),
            timeoutSeconds: $lyricsTimeout,
        );

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
