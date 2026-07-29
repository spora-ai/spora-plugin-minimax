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
 * Generates a short video clip from a text prompt via MiniMax's
 * `video_generation` API. Two operations:
 *
 *   - `generate` (default) — submit a prompt, poll status until
 *     `Success` or `Fail`, then `/v1/files/retrieve` for the MP4.
 *   - `resume` — re-attach to a previously submitted task by `task_id`
 *     and continue polling. Used when `generate` returned
 *     `success: false` with `data.timed_out: true`.
 *
 * Both operations share `submitGeneration` / `pollUntilDone` /
 * `retrieveDownloadUrl` / `archiveAndRender`; the only thing that
 * differs is whether the task is fresh (generate) or pre-existing
 * (resume).
 */
#[Tool(
    name: 'video',
    description: 'Generate a short video clip (asynchronous; up to 10s). The download URL is valid for ~1 hour. Pass `action: "resume"` with `task_id` to re-attach to an in-flight task whose first call timed out.',
    displayName: 'MiniMax Video',
    category: 'generation',
    icon: 'video',
)]
#[ToolOperation(name: 'generate', description: 'Generate a short video clip from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'resume', description: 'Continue polling a previously submitted task by id. Use when a previous `generate` returned `data.timed_out: true`.', enabledByDefault: true, requiresApprovalByDefault: false)]
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
    description: 'Video model id. Must be one of MiniMax-Hailuo-2.3, MiniMax-Hailuo-02, T2V-01-Director, T2V-01 (default: MiniMax-Hailuo-2.3).',
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
    description: 'Total wait window for video generation (default: 900). 1080P clips on Hailuo-2.3 can take 8–12 min on a busy day.',
    default: '900',
)]
#[ToolSetting(
    key: 'submit_timeout_seconds',
    label: 'Submit timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the submit API call (MiniMax queues the task server-side; default: 120).',
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
    description: 'Text prompt describing the video. Camera-movement tags like `[Pan left]`, `[Push in]` are supported (max 2000 characters). Required only for `generate`; `resume` ignores it.',
    required: ['generate'],
    maximum: 2000,
)]
#[ToolParameter(
    name: 'duration_seconds',
    type: 'string',
    description: 'Target video duration in seconds (`"6"` or `"10"`). 10s is only supported by `MiniMax-Hailuo-2.3` and `MiniMax-Hailuo-02` at 768P — see the *Resolution × duration matrix* in the skill.',
    required: false,
    enum: ['6', '10'],
    default: '6',
)]
#[ToolParameter(
    name: 'resolution',
    type: 'string',
    description: 'Video resolution. One of `720P`, `768P`, `1080P` (uppercase P, exact match). Allowed values depend on the model and duration — see the *Resolution × duration matrix* in the skill. `resume` accepts it (preferred) so the timeout-failure data on `generate` can be replayed verbatim.',
    required: false,
    enum: self::RESOLUTIONS,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "forest-push-in"). The correct file extension is appended automatically. When omitted, a speaking name is generated from the prompt.',
    required: false,
    maximum: 120,
)]
#[ToolParameter(
    name: 'poll_timeout_seconds',
    type: 'integer',
    description: 'Override `poll_timeout_seconds` for this call only (10–3600). Useful when the agent suspects a long-running generation and wants to give up faster (or wait longer than the operator-configured setting). Ignored by `resume` — pass it there too if you want a different ceiling.',
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
final class MiniMaxVideoTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'video';
    protected const DEFAULT_MODEL   = 'MiniMax-Hailuo-2.3';
    protected const QUALIFIED_NAME  = 'minimax:video';
    protected const TIMEOUT_SECONDS = 120;
    protected const TOOL_LABEL      = 'Video generation';

    /**
     * Hard floor for `poll_interval_seconds`. Operators can configure
     * the setting lower, but the loop clamps below this so a zero / negative
     * value can't spin a busy poll against a stalled endpoint.
     */
    protected const MIN_POLL_INTERVAL_SECONDS = 1;

    /**
     * Hard ceiling for `poll_interval_seconds`. The setting accepts
     * any positive number, but >10 minutes between status probes is
     * almost always an operator typo that masks a stuck task.
     */
    protected const MAX_POLL_INTERVAL_SECONDS = 600;

    /**
     * Per-poll HTTP timeout. Bounds the single `GET /v1/query/...`
     * request so a stalled probe can't outlive the loop's overall
     * deadline — without this, a stalled request makes the
     * `timed_out` envelope never reachable even when wall-clock time
     * has already exceeded `poll_timeout_seconds`.
     */
    protected const POLL_REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Allowed resolution values. The MiniMax video endpoint uses
     * uppercase `P` literal (`1080P`, not `1080p`) and rejects mixed
     * case. Centralised here so the tool attribute, the validator,
     * and the special-case hint all agree.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['720P', '768P', '1080P'];

    /**
     * Width-constant shortcut for {@see RESOLUTIONS}. The matrix
     * validator at {@see validateMatrix()} uses this for the
     * "10s is only 768P" hint — pulling `1080P` here keeps SonarQube
     * S1192 from flagging literal duplication.
     */
    public const RES_1080P = '1080P';

    /**
     * Per-upstream-matrix allow-list of (resolution, duration) pairs
     * for each supported model. Sourced verbatim from
     * https://platform.minimax.io/docs/api-reference/video-generation-t2v
     * (table at "duration" + "resolution").
     *
     * Lookup: `$rules[$model][$resolution]` returns the list of legal
     * durations for that combination. An empty list means the
     * combination is forbidden (e.g. `T2V-01` + `768P`).
     *
     * Effective defaults are derived from this same matrix in
     * {@see resolveEffectiveResolution()}.
     */
    private const DURATION_RULES = [
        'MiniMax-Hailuo-2.3'  => ['720P' => [6], '768P' => [6, 10], '1080P' => [6]],
        'MiniMax-Hailuo-02'   => ['720P' => [6], '768P' => [6, 10], '1080P' => [6]],
        'T2V-01-Director'     => ['720P' => [6], '768P' => [],     '1080P' => [6]],
        'T2V-01'              => ['720P' => [6], '768P' => [],     '1080P' => [6]],
    ];

    /** Models recognised by the MiniMax video endpoint, in declaration order. */
    private const SUPPORTED_MODELS = ['MiniMax-Hailuo-2.3', 'MiniMax-Hailuo-02', 'T2V-01-Director', 'T2V-01'];

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
     * Multi-operation dispatcher. Mirrors MiniMaxMusicTool::execute()
     * and MiniMaxSpeechTool::execute().
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
            'resume'   => "Resume video polling for task: '{$taskId}'",
            default    => "Generate video for prompt: '{$prompt}'",
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
        throw new LogicException('MiniMaxVideoTool dispatches per-operation; the base validateArguments() is never reached.');
    }

    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        throw new LogicException('MiniMaxVideoTool dispatches per-operation; the base doWork() is never reached.');
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
     * Validate the `generate` operation's inputs.
     *
     * Catches every combination the upstream matrix (see {@see DURATION_RULES})
     * rejects, *before* a submit burns quota. Also rejects:
     *   - empty / oversized prompt
     *   - non-enum `duration_seconds`
     *   - non-enum `resolution` (case-sensitive — upstream wants `1080P`, not `1080p`)
     *   - non-enum `model` setting (operator typo)
     *   - any (model, resolution, duration) triple not in the matrix
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateGenerateArguments(array $arguments): ?ToolResult
    {
        return $this->validateCommonInputs($arguments, requirePrompt: true);
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
     * Shared prompt / duration / resolution / model validation used
     * by `generate`. `resume` doesn't need it because all four fields
     * are upstream-fixed at submit time.
     *
     * @param array<string, mixed> $arguments
     */
    private function validateCommonInputs(array $arguments, bool $requirePrompt): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($requirePrompt) {
            if ($prompt === '') {
                $errors[] = 'Prompt cannot be empty.';
            }
            if (mb_strlen($prompt) > 2000) {
                $errors[] = 'Prompt exceeds the 2000-character MiniMax limit.';
            }
        }

        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        if (!in_array($durationRaw, ['6', '10'], true)) {
            $errors[] = 'duration_seconds must be "6" or "10".';
        }

        $resolution = trim((string) ($arguments['resolution'] ?? ''));
        if ($resolution !== '' && !in_array($resolution, self::RESOLUTIONS, true)) {
            $errors[] = 'resolution must be "720P", "768P", or "1080P" (uppercase P, exact match).';
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Pick the effective resolution. This is the value that gets sent
     * to MiniMax when the LLM omitted `resolution`, so the
     * cross-product check below must run against the *effective*
     * value, not the user-supplied one.
     *
     * The Hailuo family defaults to 768P (the only resolution that
     * supports 10 s); the T2V-01 family has no 768P at all and falls
     * back to 720P. `duration` doesn't affect the choice.
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
            'MiniMax-Hailuo-2.3', 'MiniMax-Hailuo-02' => '768P',
            default                                    => '720P',
        };
    }

    /**
     * Validate the (model, resolution, duration) cross-product. Called
     * *after* {@see validateCommonInputs()} so per-field errors land
     * first and the cross-product error only fires when all three are
     * individually valid.
     *
     * The matrix is the single source of truth (see {@see DURATION_RULES}).
     * Adding a new MiniMax model is a one-line change to the matrix.
     *
     * Three failure modes collapse into a single accumulator and one
     * return at the bottom — keeps the function inside the SonarQube
     * S1142 (≤3 returns) and S3776 (CC ≤15) bounds without
     * extracting a helper method.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $settings
     */
    private function validateMatrix(array $arguments, array $settings): ?ToolResult
    {
        $model      = MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL);
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = (int) $durationRaw;
        $resolution  = $this->resolveEffectiveResolution($model, $arguments);

        $message = null;
        if (!in_array($model, self::SUPPORTED_MODELS, true)) {
            $message = sprintf(
                'model "%s" is not a supported MiniMax video model. Allowed: %s.',
                $model,
                implode(', ', self::SUPPORTED_MODELS),
            );
        } else {
            $rules = self::DURATION_RULES[$model];
            if (!array_key_exists($resolution, $rules)) {
                $message = sprintf(
                    'resolution "%s" is not supported by model "%s". Allowed: %s.',
                    $resolution,
                    $model,
                    implode(', ', array_keys($rules)),
                );
            } elseif (!in_array($duration, $rules[$resolution], true)) {
                $allowedDurations = $rules[$resolution];
                sort($allowedDurations);
                $allowedList = implode('/', $allowedDurations) . 's';

                // Special-case the most common trap (1080P + 10s) with an
                // actionable hint instead of a flat "not allowed".
                $hint = ($resolution === self::RES_1080P && $duration === 10)
                    ? ' At 10s, only 768P is supported.'
                    : '';

                $message = sprintf(
                    'resolution "%s" + duration_seconds "%d" is not a valid combination for model "%s". '
                    . 'Allowed durations at this resolution: %s.%s',
                    $resolution,
                    $duration,
                    $model,
                    $allowedList,
                    $hint,
                );
            }
        }

        return $message === null ? null : new ToolResult(false, $message);
    }

    /**
     * Per-call work for the `generate` operation. Submits, polls,
     * retrieves, archives. Errors surfaced via the standard
     * MiniMaxToolSupport::run() try/catch.
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
     * task_id and archives on success. Submits nothing — the task is
     * already in flight on MiniMax's side.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doResume(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $taskId = trim((string) ($arguments['task_id'] ?? ''));

        // Empty / malformed task_id is caught by validateResumeArguments();
        // this guard is defence-in-depth for the polling-loop callers.
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
     *
     * `generate` calls this with `$taskId = null` (we submit here),
     * then poll + retrieve + archive.
     *
     * `resume` calls this with `$taskId = $taskId` (skip the submit,
     * jump straight to poll).
     *
     * On poll-timeout, returns a failed ToolResult carrying the
     * task_id and the original prompt/duration/resolution/filename
     * so the LLM can retry with the `resume` operation verbatim.
     * Without those fields the resumed video re-archives with
     * empty-prompt/default-resolution metadata, which misrepresents
     * a 10 s / T2V task. The task continues server-side and is
     * billable, so the LLM must surface that to the user.
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

        // poll_timeout_seconds resolution order:
        //   1. Per-call ToolParameter (LLM-supplied, this call only).
        //   2. Operator setting (`poll_timeout_seconds`).
        //   3. MiniMaxSettings::PROVIDER_DEFAULTS (900 s).
        // We don't use MiniMaxSettings::timeoutSeconds() here because
        // it requires the key to be in PROVIDER_DEFAULTS and the
        // per-call override needs to win cleanly.
        $overrideTimeout = isset($arguments['poll_timeout_seconds'])
            ? (int) $arguments['poll_timeout_seconds']
            : 0;
        $settingTimeout = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 900);
        $pollTimeout = $overrideTimeout > 0 ? $overrideTimeout : $settingTimeout;

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;

        if (!$isResume) {
            $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
            $taskId = $this->submitGeneration($client, $ctx->settings, $prompt, $duration, $resolution, $submitTimeout);
        }

        $this->support->logger()?->info('MiniMaxVideoTool: video generation ' . ($isResume ? 'resumed' : 'started'), [
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
            // pollUntilDone() returned a timed-out envelope — surface it
            // as a failed ToolResult with the task_id + original
            // submission params intact so the LLM can `resume` it on
            // a subsequent call without losing metadata.
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
        $url = $file['download_url'] ?? null;
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
            'model'    => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'prompt'   => $prompt,
            'duration' => $duration,
            'resolution' => $resolution,
        ];

        $startResponse = $client->postJson('/v1/video_generation', $body, timeoutSeconds: $timeoutSeconds);
        $taskId = $startResponse['task_id'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            // Synthetic MiniMaxApiException so the shared try/catch in
            // MiniMaxToolSupport::run() logs and converts to a ToolResult.
            throw new MiniMaxApiException('MiniMax returned no task_id.', 0, $startResponse);
        }
        return $taskId;
    }

    /**
     * Poll the task until it reaches a terminal state.
     *
     * Two terminal-state outcomes are possible:
     *
     *   - `Success` → returns `['success' => true, 'data' => <response>]`.
     *     Caller archives the file and renders.
     *   - `Fail`    → throws {@see MiniMaxApiException} (the upstream
     *     error message is preserved). Caller's try/catch surfaces it
     *     as a ToolResult.
     *
     * One non-terminal outcome is possible:
     *
     *   - Timeout   → returns `['success' => false, 'data' => [task_id, timed_out=true, …]]`.
     *     Caller surfaces the failure with the task_id intact so the
     *     LLM can resume. **The task is still running on MiniMax's
     *     side and is billable** — the failure message must convey
     *     this so the operator can decide whether to wait or abandon.
     *
     * Defensive clamps:
     *   - `$intervalSeconds` is forced into
     *     [`MIN_POLL_INTERVAL_SECONDS`, `MAX_POLL_INTERVAL_SECONDS`]
     *     so an operator-set zero / negative / huge value cannot spin a
     *     busy poll against a stalled endpoint or strand a task.
     *   - `$timeoutSeconds` is forced `>= 10` (the per-call
     *     parameter validator already enforces this for the LLM path).
     *   - Each `GET /v1/query/...` is given a bounded HTTP timeout
     *     (`min(remaining, $perRequestTimeoutSeconds)`) — without it
     *     a single stalled request can outlive the loop's overall
     *     deadline and the `timed_out` envelope becomes unreachable.
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
                            'MiniMax video generation did not finish within %ds (task_id=%s). '
                            . 'The task is still running on MiniMax\'s side and is billable. '
                            . 'Increase `poll_timeout_seconds` and call `minimax_video(action: "resume", task_id: "%s", '
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

            // Bound the per-request HTTP timeout so a single stalled
            // query can never push the loop past `deadline`.
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
                $msg = is_string($baseResp['status_msg'] ?? null) ? $baseResp['status_msg'] : 'video generation failed';
                throw new MiniMaxApiException("MiniMax video generation failed: {$msg}", 0, $baseResp);
            }

            $this->support->logger()?->debug('MiniMaxVideoTool: still processing, sleeping', [
                'task_id'  => $taskId,
                'status'   => $status,
                'interval' => $intervalSeconds,
            ]);
            sleep($intervalSeconds);
        }
    }

    /**
     * Final stage: retrieve the MP4 download URL, archive it via
     * MediaArchiveService (with fallback to the CDN URL on archive
     * failure), and return the rendered ToolResult.
     *
     * Bundling `$taskOutcome` keeps the parameter count inside the
     * SonarQube S107 (≤7) bound. The bundle keys are:
     *   - `task_id`        MiniMax task id (from `submitGeneration`).
     *   - `final_response` The upstream `Success` envelope (carries
     *                       `file_id` + `video_width` / `video_height`).
     *   - `duration`       Effective duration used at submit time.
     *   - `resolution`     Effective resolution used at submit time.
     *   - `prompt`         Trimmed prompt (for the archive row's
     *                       `prompt` column and filename seed).
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

        // The retrieve response carries a `download_url` valid for ~1 hour.
        $retrieveTimeout = $this->resolveTimeout('retrieve_timeout_seconds', $ctx->settings, 30);
        $downloadUrl = $this->retrieveDownloadUrl($client, $fileId, $retrieveTimeout);

        $sizeLine = ($width !== null && $height !== null) ? " ({$width}x{$height})" : '';
        if ($downloadUrl === null) {
            return new ToolResult(
                false,
                "MiniMax video succeeded (task_id={$taskId}, file_id={$fileId}) "
                . "but the file-retrieve API did not return a download_url. "
                . "Try again or fetch the file directly from your MiniMax dashboard.",
            );
        }

        // Ingest failures must never break the tool — fall back to the CDN URL.
        $archiveAsset = null;
        try {
            $archiveAsset = $this->mediaArchive()->ingest(new MediaIngestRequest(
                url: $downloadUrl,
                agentId: $ctx->agentId,
                pluginSlug: 'minimax',
                toolName: 'video',
                prompt: $prompt,
                width: $width,
                height: $height,
                durationSeconds: (float) $duration,
                filename: self::resolveFilename(
                    isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                    $prompt,
                    'minimax-video',
                    'mp4',
                ),
            ));
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (video)', [
                'exception' => $e,
                'url'       => $downloadUrl,
            ]);
        }

        // `archived` distinguishes the two states the trailing
        // instruction has to acknowledge — the rendered `<video>` is
        // served by the Media Archive, or it isn't. Without this
        // split the LLM writes a `<video>` tag with a CDN `src` that
        // 404s an hour later and renders with `src="/api/v1/assets/…"`
        // wording that doesn't match the actual URL.
        $archived = $archiveAsset !== null
            && $archiveAsset->asset_url !== ''
            && !str_starts_with($archiveAsset->asset_url, 'data:');
        $archiveUrl = $archived ? $archiveAsset->asset_url : null;
        $embedUrl   = $archiveUrl ?? $downloadUrl;
        $sizeNote   = $archiveUrl !== null
            ? ''
            : ' (URL valid ~1 hour)';

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
