<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax;

use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\TranscriptionResult;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * MiniMax Speech-to-Text (asr-1.0) — one-shot transcription via
 * `POST https://api.minimax.io/v1/speech_to_text`.
 *
 * Wire shape (verified against
 * https://platform.minimax.io/docs/api-reference/speech-to-text):
 *
 *   - Request: `multipart/form-data` with a `model` part (always `asr-1.0`
 *     today), a `file` part (the audio bytes — wav / aiff / flac / alac /
 *     mp3 / aac / opus / ogg; raw PCM without a container is rejected
 *     upstream), and an optional `response_format` part (`json` default;
 *     `verbose_json` / `srt` / `vtt` not exposed — see Out of Scope below).
 *     A `language: <bcp47>` request header is set when the controller
 *     passes a non-empty `$languageHint`; omitting the header enables
 *     mixed-language autodetect across the 20 supported tags.
 *   - Response (json): `{text: string, duration: float, trace_id: string}`.
 *     `text` empty → `InvalidAudioException`. `duration` absent → null.
 *
 * Constraints (enforced upstream, mirrored client-side for cheap failure):
 *   - max audio duration 500 s → upstream 400
 *   - max audio size 50 MB → upstream 413 (we mirror pre-flight)
 *
 * 20 supported BCP-47 tags (per the API doc): `zh, yue, en, ja, ko, th, vi,
 * id, ms, fil, ar, tr, fr, de, es, it, pt, pl, ru, uk`.
 *
 * ## Out of scope for this build
 *
 * `verbose_json` / `srt` / `vtt` response formats are accepted upstream but
 * deliberately not surfaced — they enable speaker diarization and forced
 * alignment which would need a richer `TranscriptionResult` shape than the
 * current `text / durationMs / metadata` contract provides. Operators
 * asking for diarization should pin a follow-up that maps `segments[]` /
 * `n_speakers` into `metadata`.
 *
 * SSE streaming (`stream=true`) is not supported — `SpeechToTextProviderInterface`
 * is sync-only.
 */
#[ToolSetting(
    key: 'api_key',
    label: 'MiniMax API Key',
    type: 'password',
    required: true,
    description: 'API key for api.minimax.io. Can be shared with the speech TTS tool.',
)]
#[ToolSetting(
    key: 'display_name',
    label: 'Display name',
    type: 'text',
    description: 'Operator-facing label surfaced in the recording-button gate, the speech provider config list, and the per-agent speech settings section. Default `MiniMax Speech-to-Text`. Rename per-agent / per-user to disambiguate when several STT providers are configured.',
    default: 'MiniMax Speech-to-Text',
)]
#[ToolSetting(
    key: 'model',
    label: 'Model',
    type: 'text',
    description: 'MiniMax ASR model id. Per https://platform.minimax.io/docs/api-reference/speech-to-text the only currently shipped model is `asr-1.0`. Field is exposed so operators can pin to a specific build or migrate when MiniMax ships a successor.',
    default: 'asr-1.0',
)]
#[ToolSetting(
    key: 'base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'http_timeout_seconds',
    label: 'Transcribe HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for `/v1/speech_to_text`. MiniMax\'s docs cap audio at 500 s; 60 s is the conventional safety ceiling for typical utterances.',
    default: '60',
)]
final class MiniMaxTranscribeProvider implements SpeechToTextProviderInterface
{
    private const DEFAULT_DISPLAY_NAME = 'MiniMax Speech-to-Text';
    private const DEFAULT_MODEL        = 'asr-1.0';
    private const DEFAULT_BASE_URL     = 'https://api.minimax.io';
    private const DEFAULT_TIMEOUT      = 60;
    private const MAX_BYTES            = 50 * 1024 * 1024;

    // Non-promoted runtime state — the registry rebinds this between
    // describe() calls so multi-tenant requests don't bleed labels.
    // PHP forbids re-assigning a readonly property outside the
    // constructor, so the class is declared `final` (not `final
    // readonly`) to allow bindLabel() to mutate this single field.
    // Constructor-promoted dependencies below are still never
    // reassigned. Mirrors the pattern on MuseTranscribeProvider.
    private ?string $boundDisplayName = null;

    /**
     * v2-cascade settings pushed by {@see bindSettings()}. `null` until
     * the registry resolves a `SpeechProviderConfiguration` row for
     * this provider class; the legacy `ToolConfigService` path is the
     * fallback for that case (so operators who configured the key
     * via the pre-PR-#238 `tool_user_settings` path keep working).
     */
    private ?array $boundSettings = null;

    public function __construct(
        private HttpClientInterface $http,
        private ToolConfigService $configService,
    ) {}

    public function getName(): string
    {
        return 'minimax';
    }

    public function getDisplayName(): string
    {
        return $this->boundDisplayName ?? self::DEFAULT_DISPLAY_NAME;
    }

    /**
     * When bound settings are present (v2-cascade path), the gate is
     * the `api_key` field — empty means the operator hasn't saved a key
     * yet, and the registry filters the provider out so the transcribe
     * endpoint returns 503 instead of throwing mid-call. The legacy
     * optimistic default (defer to `transcribe()` for the real check)
     * is kept for the no-bound-settings path so v1
     * `tool_user_settings` operators keep working without an admin-side
     * re-save.
     */
    public function isConfigured(): bool
    {
        if ($this->boundSettings === null) {
            return true;
        }
        $apiKey = $this->boundSettings['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    /**
     * Cache the operator's per-config `display_name` ToolSetting. The
     * registry calls this once per `describe()` invocation so subsequent
     * {@see getDisplayName()} calls return the operator's label rather
     * than the class-level default. The registry gates the call with
     * `method_exists()` so older spora-core versions that don't yet
     * resolve `display_name` for class-level providers simply skip this
     * hook and keep the static name. Mirrors the pattern on
     * {@see \Spora\Plugins\Muse\MuseTranscribeProvider::bindLabel()}.
     */
    public function bindLabel(string $label): void
    {
        $this->boundDisplayName = $label;
    }

    /**
     * Cache the operator's v2-cascade settings (decoded by
     * {@see \Spora\Services\SpeechProviderConfigPersistence::decodeSettings()})
     * so {@see transcribe()} and {@see isConfigured()} consult the
     * `speech_provider_configurations` cascade — the single source of
     * truth post-{@see https://github.com/spora-ai/spora-core/pull/238 PR #238}.
     * Falls back to `ToolConfigService` only when no bound settings
     * are present (legacy v1 `tool_user_settings` operators keep
     * working without an admin-side re-save). The registry resets
     * the bound value on every `configuredProvider()` call so
     * multi-tenant requests don't bleed settings across calls.
     *
     * @param array<string, mixed> $settings
     */
    public function bindSettings(array $settings): void
    {
        $this->boundSettings = $settings;
    }

    /**
     * Test accessor for the bound settings. Not part of
     * {@see SpeechToTextProviderInterface} — production callers go
     * through {@see transcribe()} which reads `$this->boundSettings`
     * directly. Exposed so registry-binding tests can assert on the
     * decoded settings without reflection.
     *
     * @return array<string, mixed>
     */
    public function boundSettings(): array
    {
        return $this->boundSettings ?? [];
    }

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        return $this->runTranscribe($bytes, $mimeType, $languageHint, $agentId ?? 0, $userId ?? 0);
    }

    /**
     * Worker for {@see transcribe()}. Extracted to keep the public
     * method's cognitive complexity at 0 — `transcribe()` is the
     * user-facing entry point and should be a single delegating call
     * so the S3776 threshold is honoured even after future growth.
     */
    private function runTranscribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint,
        int $agentId,
        ?int $userId,
    ): TranscriptionResult {
        $settings = $this->boundSettings
            ?? $this->configService->getEffectiveSettings(self::class, $agentId, $userId);

        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        $this->validateInputs($bytes, $apiKey);

        $request = $this->buildRequestDescriptor($settings, $bytes, $mimeType, $languageHint, $apiKey);

        $payload = $this->dispatch($request['url'], $request['headers'], $request['multipart'], $request['timeout']);

        return $this->parseResponse($payload, $languageHint, $request['model']);
    }

    /**
     * Pre-flight checks that must fail before any HTTP call. Extracted
     * from {@see transcribe()} to keep the cognitive complexity of the
     * public method under the SonarQube S3776 threshold (≤15).
     */
    private function validateInputs(string $bytes, string $apiKey): void
    {
        if ($apiKey === '') {
            throw new SpeechToTextException('MiniMax API Key is not configured for this user.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidAudioException(sprintf(
                'Audio exceeds MiniMax %d MB file cap.',
                self::MAX_BYTES / 1024 / 1024,
            ));
        }
    }

    /**
     * Build the `{url, headers, multipart, timeout, model}` tuple the
     * dispatcher consumes. Extracted from {@see transcribe()} to keep
     * the cognitive complexity of the public method under S3776.
     *
     * Returns the resolved `model` alongside the descriptor so the
     * caller can stamp it into {@see TranscriptionResult::metadata}
     * without re-reading the settings array.
     *
     * @param array<string, mixed> $settings
     * @return array{url: string, headers: array<string, string>, multipart: list<array<string, mixed>>, timeout: int, model: string}
     */
    private function buildRequestDescriptor(
        array $settings,
        string $bytes,
        string $mimeType,
        ?string $languageHint,
        string $apiKey,
    ): array {
        $baseUrl = $this->resolveBaseUrl($settings);
        $model   = $this->resolveModel($settings);
        $timeout = $this->resolveTimeout($settings);

        $headers = ['Authorization' => 'Bearer ' . $apiKey];
        if ($languageHint !== null && trim($languageHint) !== '') {
            $headers['language'] = trim($languageHint);
        }

        $multipart = [
            ['name' => 'model', 'contents' => $model],
            [
                'name' => 'file',
                'contents' => $bytes,
                'filename' => 'audio',
                'content_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            ],
            ['name' => 'response_format', 'contents' => 'json'],
        ];

        return [
            'url'       => rtrim($baseUrl, '/') . '/v1/speech_to_text',
            'headers'   => $headers,
            'multipart' => $multipart,
            'timeout'   => $timeout,
            'model'     => $model,
        ];
    }

    /**
     * Dispatch the multipart POST and unwrap the response into the
     * upstream's `{text, duration, trace_id}` payload. Translates every
     * transport / upstream failure into a
     * {@see SpeechToTextException} (or {@see InvalidAudioException}
     * for the 413 over-cap path) so the controller only needs to
     * translate one exception type.
     *
     * @param array<string, string>           $headers
     * @param list<array<string, mixed>>      $multipart
     * @return array<string, mixed>
     */
    private function dispatch(string $url, array $headers, array $multipart, int $timeout): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers,
                'multipart' => $multipart,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw $this->buildHttpException($response, $statusCode);
            }

            return $response->toArray();
        } catch (SpeechToTextException $e) {
            // `InvalidAudioException extends SpeechToTextException` (see
            // `app/Speech/InvalidAudioException.php` on
            // `fix/review-findings-core`), so this re-throw is the
            // choke point that preserves both the pre-flight MIME/size
            // failures AND the 413 over-cap path that
            // buildHttpException() also produces.
            throw $e;
        } catch (HttpClientException $e) {
            // Symfony's `TransportException` implements
            // `TransportExceptionInterface extends ExceptionInterface`
            // which we alias as `HttpClientException`, so this catch
            // covers every transport-level failure (timeouts, SSL,
            // DNS, network reset). Catch the parent interface, not
            // the concrete class.
            throw new SpeechToTextException('MiniMax STT request failed: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new SpeechToTextException('MiniMax STT request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Translate the upstream `{text, duration, trace_id}` payload into
     * a {@see TranscriptionResult}. Extracted from {@see transcribe()}
     * to keep the public method's cognitive complexity under S3776.
     *
     * @param array<string, mixed> $payload
     */
    private function parseResponse(array $payload, ?string $languageHint, string $model): TranscriptionResult
    {
        $text = is_string($payload['text'] ?? null) ? trim($payload['text']) : '';
        if ($text === '') {
            throw new InvalidAudioException('MiniMax STT returned no transcript.');
        }

        $durationMs = null;
        if (isset($payload['duration']) && is_numeric($payload['duration'])) {
            $durationMs = (float) $payload['duration'] * 1000.0;
        }

        $traceId = is_string($payload['trace_id'] ?? null) ? $payload['trace_id'] : null;

        return new TranscriptionResult(
            text: $text,
            language: $languageHint !== null && trim($languageHint) !== '' ? trim($languageHint) : null,
            durationMs: $durationMs,
            metadata: [
                'trace_id' => $traceId,
                'model'    => $model,
            ],
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveBaseUrl(array $settings): string
    {
        $value = $settings['base_url'] ?? self::DEFAULT_BASE_URL;
        if (!is_string($value) || trim($value) === '') {
            return self::DEFAULT_BASE_URL;
        }
        return trim($value);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveModel(array $settings): string
    {
        $value = $settings['model'] ?? self::DEFAULT_MODEL;
        if (!is_string($value) || trim($value) === '') {
            return self::DEFAULT_MODEL;
        }
        return trim($value);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveTimeout(array $settings): int
    {
        $value = $settings['http_timeout_seconds'] ?? self::DEFAULT_TIMEOUT;
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        $env = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $env > 0 ? $env : self::DEFAULT_TIMEOUT;
    }

    /**
     * Build a `SpeechToTextException` (or `InvalidAudioException` for the
     * 413 over-cap path) carrying the upstream `error.message` verbatim.
     * The upstream wording preserves MiniMax's internal error code in
     * parentheses (e.g. `(2013)`) which operators rely on for triage.
     *
     * Exception messages MUST NOT include the API key — see
     * `SpeechToTextException`. The MiniMax error envelope puts the key
     * in the Authorization header, not the body, so passing the body
     * through verbatim is safe by construction.
     */
    private function buildHttpException(\Symfony\Contracts\HttpClient\ResponseInterface $response, int $statusCode): SpeechToTextException|InvalidAudioException
    {
        $message = $this->extractUpstreamMessage($response);

        return match ($statusCode) {
            413 => new InvalidAudioException(
                $message !== '' ? $message : 'Audio exceeds MiniMax 50 MB cap.',
            ),
            default => new SpeechToTextException(
                $message !== '' ? $message : sprintf('MiniMax STT returned HTTP %d.', $statusCode),
            ),
        };
    }

    private function extractUpstreamMessage(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        try {
            // `toArray($throw = false)` returns the decoded body even on
            // 4xx / 5xx so we can read the upstream's OaiError envelope.
            // Returns `array<string, mixed>` per the interface contract;
            // PHPStan knows the return type, no need to re-validate with
            // is_array() here.
            $body = $response->toArray(false);
        } catch (Throwable) {
            return '';
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        return is_string($error['message'] ?? null) ? trim($error['message']) : '';
    }
}
