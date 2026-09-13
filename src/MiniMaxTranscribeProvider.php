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
     * Optimistic — `isConfigured()` returning false would make the
     * capability endpoint advertise this provider as not-configured even
     * when the operator only forgot to enable it on this agent. The
     * `api_key` check happens per-call in {@see transcribe()}, which
     * throws a clear `SpeechToTextException` if the key is empty.
     * Matches the MuseTranscribeProvider pattern.
     */
    public function isConfigured(): bool
    {
        return true;
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

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $settings = $this->configService->getEffectiveSettings(self::class, $agentId ?? 0, $userId);

        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        if ($apiKey === '') {
            throw new SpeechToTextException('MiniMax API Key is not configured for this user.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidAudioException(sprintf(
                'Audio exceeds MiniMax %d MB file cap.',
                self::MAX_BYTES / 1024 / 1024,
            ));
        }

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

        try {
            $response = $this->http->request('POST', rtrim($baseUrl, '/') . '/v1/speech_to_text', [
                'headers' => $headers,
                'multipart' => $multipart,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw $this->buildHttpException($response, $statusCode);
            }

            $payload = $response->toArray();
        } catch (SpeechToTextException $e) {
            // `InvalidAudioException extends SpeechToTextException` (see
            // `app/Speech/InvalidAudioException.php` on `fix/review-findings-core`),
            // so this re-throw is the choke point that preserves both the
            // pre-flight MIME/size failures AND the 413 over-cap path that
            // buildHttpException() also produces.
            throw $e;
        } catch (HttpClientException $e) {
            // Symfony's `TransportException` implements `TransportExceptionInterface
            // extends ExceptionInterface` which we alias as `HttpClientException`,
            // so this catch covers every transport-level failure (timeouts,
            // SSL, DNS, network reset). Catch the parent interface, not the
            // concrete class.
            throw new SpeechToTextException('MiniMax STT request failed: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new SpeechToTextException('MiniMax STT request failed: ' . $e->getMessage(), 0, $e);
        }

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
        $msg   = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        return $msg;
    }
}
