<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use LogicException;
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Legacy v1 video tool — MiniMax's `/v1/video_generation` endpoints.
 *
 * Re-introduced in 1.3.0 as a fall-back for operators whose TokenPlan
 * doesn't include MiniMax-H3 (the v2-only model). The error from the v2
 * endpoint — "invalid params, TokenPlan or Credit does not currently
 * support MiniMax-H3 series models (2013)" — indicates exactly this
 * case. The orchestrator's system prompt guides the LLM to fall back
 * to `minimax:video_v1` with the same prompt when it sees that message.
 *
 * Two operations:
 *   - `generate` (default) — submit a prompt (or `first_frame_image`
 *     for i2v), poll status until `Success` or `Fail`, then
 *     `/v1/files/retrieve` for the MP4 download URL.
 *   - `resume` — re-attach to a previously submitted task by `task_id`
 *     and continue polling. Used when `generate` returned
 *     `success: false` with `data.timed_out: true`.
 *
 * Models, resolutions, and durations are validated against the v1
 * matrix (see {@see MiniMaxVideoV1Matrix}) before any upstream call so
 * the LLM gets a clear client-side error instead of an upstream 400.
 * The matrix recognises the eight v1 models (four t2v + four i2v)
 * but this implementation only ships the t2v code path; i2v
 * (`first_frame_image` flip) entry points land in a follow-up PR so
 * the matrix validator is in place from day one.
 *
 * Sister tool to {@see MiniMaxVideoTool} (the H3 / v2 tool).
 */
#[Tool(
    name: 'video_v1',
    description: 'Legacy MiniMax video generation (v1 API). Fall-back for plans that don\'t include MiniMax-H3. Supports text-to-video via `prompt`; image-to-video via `first_frame_image` is accepted by the matrix but routes to the t2v path until the i2v code path lands. Models: MiniMax-Hailuo-2.3 (default), MiniMax-Hailuo-02, T2V-01-Director, T2V-01. Resolutions + durations are validated against the v1 matrix before submit.',
    displayName: 'MiniMax Video (v1 legacy)',
    category: 'generation',
    icon: 'video',
)]
#[ToolOperation(name: 'generate', description: 'Submit a v1 video task (text prompt). Polls until Success or timeout, then retrieves the download URL via /v1/files/retrieve.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'resume', description: 'Continue polling a previously submitted v1 task by id. Use when a previous `generate` returned `data.timed_out: true`.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io. Can be shared with the v2 video tool.',
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
    description: 'Video model id. Must be one of MiniMax-Hailuo-2.3 (default), MiniMax-Hailuo-02, T2V-01-Director, T2V-01. The i2v siblings (MiniMax-Hailuo-2.3-Fast, I2V-01-Director, I2V-01-live, I2V-01) are listed in the matrix but rejected by this build until the i2v code path lands.',
    default: 'MiniMax-Hailuo-2.3',
)]
#[ToolSetting(
    key: 'poll_interval_seconds',
    label: 'Poll interval (s)',
    type: 'text',
    description: 'Seconds between status polls (default: 10).',
    default: '10',
)]
#[ToolSetting(
    key: 'poll_timeout_seconds',
    label: 'Poll timeout (s)',
    type: 'text',
    description: 'Total wait window for v1 video generation (default: 900). 1080P clips on Hailuo-2.3 routinely hit 8 to 12 min on a busy day.',
    default: '900',
)]
#[ToolSetting(
    key: 'submit_timeout_seconds',
    label: 'Submit timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the v1 submit call (MiniMax queues the task server-side; default: 120).',
    default: '120',
)]
#[ToolSetting(
    key: 'retrieve_timeout_seconds',
    label: 'File retrieve timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the /v1/files/retrieve call (default: 30).',
    default: '30',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Text prompt describing the video (max 2000 characters). Camera-movement tags like `[Pan left]`, `[Push in]` are supported on the Hailuo family. Required only for `generate`; `resume` ignores it.',
    required: ['generate'],
    maximum: 2000,
)]
#[ToolParameter(
    name: 'first_frame_image',
    type: 'string',
    description: 'URL or `data:image/...;base64,...` URI of the starting frame for i2v. NOT YET IMPLEMENTED — the v1 matrix currently rejects any call that supplies this parameter with a clear "i2v code path not yet shipped" message. The H3 / v2 tool accepts it.',
    required: false,
)]
#[ToolParameter(
    name: 'duration_seconds',
    type: 'string',
    description: 'Target video duration in seconds (`"6"` or `"10"`). 10s is only supported by `MiniMax-Hailuo-2.3` and `MiniMax-Hailuo-02` at 768P — see the *Resolution x duration matrix* in the skill.',
    required: false,
    enum: ['6', '10'],
    default: '6',
)]
#[ToolParameter(
    name: 'resolution',
    type: 'string',
    description: 'Video resolution. One of `512P`, `720P`, `768P`, `1080P` (uppercase P, exact match). Allowed values depend on the model and duration — see the *Resolution x duration matrix* in the skill.',
    required: false,
    enum: MiniMaxVideoV1Matrix::RESOLUTIONS,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "rooftop-sunset"). The correct extension (.mp4) is appended automatically. When omitted, a speaking name is generated from the prompt.',
    required: false,
    maximum: 120,
)]
#[ToolParameter(
    name: 'poll_timeout_seconds',
    type: 'integer',
    description: 'Per-call override for `poll_timeout_seconds` setting. Useful for `resume` calls with a longer wait window.',
    required: false,
    minimum: 10,
    maximum: 3600,
)]
#[ToolParameter(
    name: 'task_id',
    type: 'string',
    description: 'The MiniMax task id from a previous `generate` call. Required only for `resume`.',
    required: ['resume'],
)]
final class MiniMaxVideoV1Tool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'video_v1';
    protected const DEFAULT_MODEL   = 'MiniMax-Hailuo-2.3';
    protected const QUALIFIED_NAME  = 'minimax:video_v1';
    protected const TIMEOUT_SECONDS = 120;
    protected const TOOL_LABEL      = 'Video generation (v1)';

    /** Hard floor for `poll_interval_seconds`. */
    protected const MIN_POLL_INTERVAL_SECONDS = 1;

    /** Hard ceiling for `poll_interval_seconds`. */
    protected const MAX_POLL_INTERVAL_SECONDS = 600;

    /** Per-poll HTTP timeout. Bounds the single GET /v1/query/... request. */
    protected const POLL_REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        \Spora\Services\ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        \Spora\Plugins\MiniMax\Support\MiniMaxLogWriter $logWriter,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\Spora\Plugins\MiniMax\Support\MiniMaxToolSupport $support = null,
        ?\Spora\Services\MediaArchive\MediaArchiveService $mediaArchive = null,
    ) {
        parent::__construct($configService, $httpClient, $logWriter, $logger, $support);
        $this->attachVideoMediaArchive($mediaArchive);
    }

    private function attachVideoMediaArchive(?\Spora\Services\MediaArchive\MediaArchiveService $archive): void
    {
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    /**
     * Multi-operation dispatcher. Mirrors {@see MiniMaxVideoTool::execute()}.
     *
     * Backward compat: pre-multi-op callers never passed `action` and
     * landed on `generate` (the only operation at the time). The
     * default match arm keeps that path alive for existing agent
     * definitions; any unrecognised `action` value fails loudly so a
     * typo doesn't silently fall back to `generate`.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        // Resolve Media Archive references BEFORE dispatching — each
        // per-operation method (`generate()`, `resume()`) builds an
        // `fn()` work closure that captures `$arguments` by value at
        // definition time, so rebinding it inside `runWithValidation()`
        // never reaches `doGenerate()`/`doResume()`. See
        // {@see MiniMaxTool::resolveMediaArchiveReferences()}.
        $resolved = $this->resolveMediaArchiveReferences($arguments, $userId);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }
        $arguments = $resolved;

        $operation = (string) ($arguments['action'] ?? 'generate');
        return match ($operation) {
            'generate' => $this->generate($arguments, $agentId, $userId),
            'resume'   => $this->resume($arguments, $agentId, $userId),
            default    => new ToolResult(false, "Unknown video operation: {$operation}. Expected 'generate' or 'resume'."),
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
        $operation = (string) ($arguments['action'] ?? 'generate');
        $prompt    = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        $taskId    = mb_substr(trim((string) ($arguments['task_id'] ?? '')), 0, 40);

        return match ($operation) {
            'resume' => "Resume v1 video polling for task: '{$taskId}'",
            default  => "Generate v1 video for prompt: '{$prompt}'",
        };
    }

    /**
     * Base-class hooks unused by this multi-operation tool — dispatch
     * happens in {@see execute()}. Throwing surfaces accidental calls.
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateArguments(array $arguments): ?ToolResult
    {
        throw new LogicException('MiniMaxVideoV1Tool dispatches per-operation; the base validateArguments() is never reached.');
    }

    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        throw new LogicException('MiniMaxVideoV1Tool dispatches per-operation; the base doWork() is never reached.');
    }

    /** @param array<string, mixed> $arguments */
    public function generate(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            static::TOOL_LABEL,
            fn(MiniMaxToolContext $c) => $this->doGenerate($c, $arguments),
            fn(array $a) => $this->validateGenerateArguments($a),
        );
    }

    /** @param array<string, mixed> $arguments */
    public function resume(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            static::TOOL_LABEL,
            fn(MiniMaxToolContext $c) => $this->doResume($c, $arguments),
            fn(array $a) => $this->validateResumeArguments($a),
        );
    }

    /**
     * Validate the `generate` operation's inputs. Per-field checks
     * only — the (model, resolution, duration) cross-product check
     * happens inside {@see doGenerate()} once `$ctx->settings` is
     * available, matching the original v1 pattern. So the
     * client-side validation here runs without DI reflection.
     *
     * Rejects:
     *   - empty / oversized prompt
     *   - non-enum `duration_seconds`
     *   - non-enum `resolution` (case-sensitive — upstream wants `1080P`, not `1080p`)
     *   - `first_frame_image` (i2v code path not yet shipped in v1)
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateGenerateArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            $errors[] = 'Prompt cannot be empty.';
        }
        if (mb_strlen($prompt) > 2000) {
            $errors[] = 'Prompt exceeds the 2000-character MiniMax limit.';
        }

        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        if (!in_array($durationRaw, MiniMaxVideoV1Matrix::DURATIONS, true)) {
            $errors[] = sprintf(
                'duration_seconds must be one of %s (string).',
                implode(' / ', MiniMaxVideoV1Matrix::DURATIONS),
            );
        }

        $resolution = trim((string) ($arguments['resolution'] ?? ''));
        if ($resolution !== '' && !in_array($resolution, MiniMaxVideoV1Matrix::RESOLUTIONS, true)) {
            $errors[] = sprintf(
                'resolution must be one of %s (uppercase P, exact match).',
                implode(', ', MiniMaxVideoV1Matrix::RESOLUTIONS),
            );
        }

        $firstFrame = trim((string) ($arguments['first_frame_image'] ?? ''));
        if ($firstFrame !== '') {
            $errors[] = 'first_frame_image is accepted by the v1 matrix but the i2v code path is not yet shipped in minimax:video_v1. Use minimax:video (H3) for image-to-video, or omit the parameter for plain text-to-video.';
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Validate the `resume` operation's inputs — just the `task_id`
     * is required. Prompt / resolution / duration are ignored (the
     * task is already in flight).
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateResumeArguments(array $arguments): ?ToolResult
    {
        $taskId = trim((string) ($arguments['task_id'] ?? ''));
        if ($taskId === '') {
            return new ToolResult(false, 'task_id is required for the resume operation.');
        }
        return null;
    }

    /**
     * Validate the (model, resolution, duration) cross-product. The
     * matrix is the single source of truth (see {@see MiniMaxVideoV1Matrix}).
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $settings
     */
    private function validateMatrix(array $arguments, array $settings): ?ToolResult
    {
        $model       = MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL);
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = (int) $durationRaw;
        $resolution  = $this->resolveEffectiveResolution($model, $arguments);

        $message = MiniMaxVideoV1Matrix::explain($model, $resolution, $duration);
        return $message === null ? null : new ToolResult(false, $message);
    }

    /**
     * Pick the effective resolution. When the LLM omitted
     * `resolution`, fall back to the model's default (Hailuo family and
     * Hailuo-2.3-Fast / I2V-01-Director -> 768P; everything else -> 720P).
     *
     * @param array<string, mixed> $arguments
     */
    private function resolveEffectiveResolution(string $model, array $arguments): string
    {
        $supplied = trim((string) ($arguments['resolution'] ?? ''));
        if ($supplied !== '') {
            return $supplied;
        }

        return match ($model) {
            'MiniMax-Hailuo-2.3',
            'MiniMax-Hailuo-02',
            'MiniMax-Hailuo-2.3-Fast',
            'I2V-01-Director' => '768P',
            default          => '720P',
        };
    }

    /**
     * Per-call work for the `generate` operation. Submits, polls,
     * retrieves, archives.
     *
     * The matrix check runs here (not in {@see validateGenerateArguments()})
     * because we need `$ctx->settings` to resolve the configured model —
     * which the v1 setter signature of `runWithValidation` doesn't
     * expose to the pre-flight validator. The matrix returns the
     * same per-axis error message the v1 validator did before.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doGenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $matrixError = $this->validateMatrix($arguments, $ctx->settings);
        if ($matrixError !== null) {
            return $matrixError;
        }

        return $this->generateAndArchive(
            $ctx,
            $arguments,
            taskId: null,
            isResume: false,
        );
    }

    /**
     * Per-call work for the `resume` operation. Polls an existing
     * task_id and archives on success. Submits nothing.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doResume(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $taskId = trim((string) ($arguments['task_id'] ?? ''));
        if ($taskId === '') {
            return new ToolResult(false, 'task_id is required for the resume operation.');
        }

        return $this->generateAndArchive(
            $ctx,
            $arguments,
            taskId: $taskId,
            isResume: true,
        );
    }

    /**
     * Common generate-and-archive path shared by both operations.
     * `generate` calls this with `$taskId = null` (we submit here);
     * `resume` calls this with `$taskId = $taskId` (skip the submit).
     *
     * On poll-timeout returns a failed ToolResult carrying the
     * task_id + original prompt/duration/resolution/filename so the
     * LLM can retry with the `resume` operation verbatim.
     *
     * @param array<string, mixed> $arguments
     */
    private function generateAndArchive(
        MiniMaxToolContext $ctx,
        array $arguments,
        ?string $taskId,
        bool $isResume,
    ): ToolResult {
        $prompt       = trim((string) ($arguments['prompt'] ?? ''));
        $durationRaw  = (string) ($arguments['duration_seconds'] ?? '6');
        $duration     = (int) $durationRaw;
        $resolution   = $this->resolveEffectiveResolution(
            MiniMaxSettings::model(self::PROVIDER, $ctx->settings, self::DEFAULT_MODEL),
            $arguments,
        );
        $filenameRaw  = isset($arguments['filename']) ? (string) $arguments['filename'] : '';

        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $ctx->settings, 10);

        $overrideTimeout = isset($arguments['poll_timeout_seconds'])
            ? (int) $arguments['poll_timeout_seconds']
            : 0;
        $settingTimeout = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 900);
        $pollTimeout    = $overrideTimeout > 0 ? $overrideTimeout : $settingTimeout;

        $client = $ctx->client;

        if (!$isResume) {
            $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
            $taskId = $this->submitGeneration(
                $client,
                $ctx->settings,
                $prompt,
                $duration,
                $resolution,
                $submitTimeout,
            );
        }

        $this->support->logger()?->info('MiniMaxVideoV1Tool: video generation ' . ($isResume ? 'resumed' : 'started'), [
            'task_id'      => $taskId,
            'interval'     => $pollInterval,
            'poll_timeout' => $pollTimeout,
            'is_resume'    => $isResume,
        ]);

        $pollResult = $this->pollUntilDone(
            $client,
            $taskId,
            $pollInterval,
            $pollTimeout,
            self::POLL_REQUEST_TIMEOUT_SECONDS,
        );
        if (!$pollResult['success']) {
            $err = $pollResult['data'];
            return new ToolResult(false, $err['content'], [
                'task_id'          => $err['task_id'],
                'status'           => $err['status'],
                'timed_out'        => $err['timed_out'],
                'prompt'           => $prompt,
                'duration_seconds' => $duration,
                'resolution'       => $resolution,
                'filename'         => $filenameRaw,
            ]);
        }
        $finalResponse = $pollResult['data'];

        $this->support->logSuccess($ctx, $finalResponse);

        return $this->archiveAndRender(
            $ctx,
            $arguments,
            $client,
            [
                'task_id'        => $taskId,
                'final_response' => $finalResponse,
                'duration'       => $duration,
                'resolution'     => $resolution,
                'prompt'         => $prompt,
            ],
        );
    }

    /**
     * Returns null if the upstream didn't return a download URL — the caller
     * surfaces a clear failure rather than pretending success.
     */
    private function retrieveDownloadUrl(MiniMaxHttpClient $client, string $fileId, int $timeoutSeconds): ?string
    {
        $response = $client->getJson(
            '/v1/files/retrieve',
            ['file_id' => $fileId],
            timeoutSeconds: $timeoutSeconds,
        );
        $file = is_array($response['file'] ?? null) ? $response['file'] : [];
        $url  = $file['download_url'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function submitGeneration(
        MiniMaxHttpClient $client,
        array $settings,
        string $prompt,
        int $duration,
        string $resolution,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'      => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'prompt'     => $prompt,
            'duration'   => $duration,
            'resolution' => $resolution,
        ];

        $startResponse = $client->postJson('/v1/video_generation', $body, timeoutSeconds: $timeoutSeconds);
        $taskId = $startResponse['task_id'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            throw new MiniMaxApiException('MiniMax returned no task_id.', 0, $startResponse);
        }
        return $taskId;
    }

    /**
     * Poll the v1 task until it reaches a terminal state.
     *
     * Terminal states (v1-specific spelling):
     *   - `Success` -> returns ['success' => true, 'data' => <response>].
     *   - `Fail`    -> throws {@see MiniMaxApiException} (the upstream
     *     `base_resp.status_msg` is preserved).
     *
     * Non-terminal:
     *   - Timeout   -> returns ['success' => false, 'data' => <envelope>].
     *     The task is still running on MiniMax's side and is billable.
     *
     * Defensive clamps:
     *   - `$intervalSeconds` is forced into
     *     [`MIN_POLL_INTERVAL_SECONDS`, `MAX_POLL_INTERVAL_SECONDS`].
     *   - Each `GET /v1/query/...` is given a bounded HTTP timeout
     *     (`min(remaining, $perRequestTimeoutSeconds)`).
     *   - The `sleep` between probes is capped to the remaining
     *     deadline so the timed_out envelope fires within
     *     `poll_timeout_seconds` instead of up to one full interval late.
     *
     * @return array{success: bool, data?: array<string, mixed>}
     */
    private function pollUntilDone(
        MiniMaxHttpClient $client,
        string $taskId,
        int $intervalSeconds,
        int $timeoutSeconds,
        int $perRequestTimeoutSeconds,
    ): array {
        $intervalSeconds = max(self::MIN_POLL_INTERVAL_SECONDS, min(self::MAX_POLL_INTERVAL_SECONDS, $intervalSeconds));
        $deadline        = microtime(true) + max(10, $timeoutSeconds);

        while (true) {
            if (microtime(true) >= $deadline) {
                return [
                    'success' => false,
                    'data' => [
                        'task_id'   => $taskId,
                        'status'    => 'still_running',
                        'timed_out' => true,
                        'content'   => sprintf(
                            'MiniMax v1 video generation did not finish within %ds (task_id=%s). '
                            . 'The task is still running on MiniMax\'s side and is billable. '
                            . 'Increase `poll_timeout_seconds` and call `minimax_video_v1(action: "resume", task_id: "%s", '
                            . 'prompt: "<original prompt>", duration_seconds: "<original duration>", '
                            . 'resolution: "<original resolution>")` to keep waiting, '
                            . 'or abandon it and accept the billed quota.',
                            $timeoutSeconds,
                            $taskId,
                            $taskId,
                        ),
                    ],
                ];
            }

            $remainingSeconds    = (int) ceil($deadline - microtime(true));
            $effectivePerRequest = max(1, min($remainingSeconds, $perRequestTimeoutSeconds));

            $response = $client->getJson(
                '/v1/query/video_generation',
                ['task_id' => $taskId],
                timeoutSeconds: $effectivePerRequest,
            );
            $status = $response['status'] ?? null;

            if ($status === 'Success') {
                return ['success' => true, 'data' => $response];
            }
            if ($status === 'Fail') {
                $baseResp = is_array($response['base_resp'] ?? null) ? $response['base_resp'] : [];
                $msg      = is_string($baseResp['status_msg'] ?? null) ? $baseResp['status_msg'] : 'video generation failed';
                throw new MiniMaxApiException("MiniMax v1 video generation failed: {$msg}", 0, $baseResp);
            }

            $this->support->logger()?->debug('MiniMaxVideoV1Tool: still processing, sleeping', [
                'task_id'  => $taskId,
                'status'   => $status,
                'interval' => $intervalSeconds,
            ]);

            $remainingAfterProbe = (int) ceil($deadline - microtime(true));
            $sleepFor            = max(1, min($intervalSeconds, $remainingAfterProbe));
            sleep($sleepFor);
        }
    }

    /**
     * Final stage: retrieve the MP4 download URL, archive it via
     * MediaArchiveService (with fallback to the CDN URL on archive
     * failure), and return the rendered ToolResult.
     *
     * @param array<string, mixed> $arguments
     * @param array{
     *   task_id: string,
     *   final_response: array<string, mixed>,
     *   duration: int,
     *   resolution: string,
     *   prompt: string,
     * } $taskOutcome
     */
    private function archiveAndRender(
        MiniMaxToolContext $ctx,
        array $arguments,
        MiniMaxHttpClient $client,
        array $taskOutcome,
    ): ToolResult {
        $taskId        = $taskOutcome['task_id'];
        $finalResponse = $taskOutcome['final_response'];
        $duration      = $taskOutcome['duration'];
        $resolution    = $taskOutcome['resolution'];
        $prompt        = $taskOutcome['prompt'];

        $fileId = is_string($finalResponse['file_id'] ?? null) ? $finalResponse['file_id'] : null;
        $width  = is_int($finalResponse['video_width'] ?? null) ? $finalResponse['video_width'] : null;
        $height = is_int($finalResponse['video_height'] ?? null) ? $finalResponse['video_height'] : null;

        if ($fileId === null) {
            return new ToolResult(false, 'MiniMax video succeeded but returned no file_id.');
        }

        $retrieveTimeout = $this->resolveTimeout('retrieve_timeout_seconds', $ctx->settings, 30);
        $downloadUrl     = $this->retrieveDownloadUrl($client, $fileId, $retrieveTimeout);

        $sizeLine = ($width !== null && $height !== null) ? " ({$width}x{$height})" : '';
        if ($downloadUrl === null) {
            return new ToolResult(
                false,
                "MiniMax video succeeded (task_id={$taskId}, file_id={$fileId}) "
                . "but the file-retrieve API did not return a download_url. "
                . 'Try again or fetch the file directly from your MiniMax dashboard.',
            );
        }

        $archiveAsset = null;
        try {
            $archiveAsset = $this->mediaArchive()->ingest(new MediaIngestRequest(
                url: $downloadUrl,
                agentId: $ctx->agentId,
                pluginSlug: 'minimax',
                toolName: 'video_v1',
                prompt: $prompt,
                width: $width,
                height: $height,
                durationSeconds: (float) $duration,
                filename: self::resolveFilename(
                    isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                    $prompt,
                    'minimax-video-v1',
                    'mp4',
                ),
            ));
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (video_v1)', [
                'exception' => $e,
                'url'       => $downloadUrl,
            ]);
        }

        $archived = $archiveAsset !== null
            && $archiveAsset->asset_url !== ''
            && !str_starts_with($archiveAsset->asset_url, 'data:');
        $archiveUrl = $archived ? $archiveAsset->asset_url : null;
        $embedUrl   = $archiveUrl ?? $downloadUrl;
        $sizeNote   = $archiveUrl !== null ? '' : ' (URL valid ~1 hour)';

        $renderInstruction = $archived
            ? "Echo the `<video>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp4` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`."
            : "Echo the `<video>` element above verbatim — its `src` is the upstream MiniMax CDN URL (valid ~1 hour); the Media Archive plugin isn't installed or this file was rejected, so the URL isn't rewritten to a long-lived `/api/v1/assets/...` path. Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.";

        $content = "Generated video{$sizeLine} for prompt: \"{$prompt}\"\n\n"
            . MediaEmbed::videoFromUrl($embedUrl, $width, $height) . "\n\n"
            . "task_id: {$taskId}  file_id: {$fileId}{$sizeNote}"
            . "\n\n" . $renderInstruction;

        return new ToolResult(true, $content, [
            'task_id'      => $taskId,
            'file_id'      => $fileId,
            'download_url' => $downloadUrl,
            'asset_url'    => $embedUrl,
            'width'        => $width,
            'height'       => $height,
            'duration'     => $duration,
            'resolution'   => $resolution !== '' ? $resolution : null,
        ]);
    }

}
