<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use LogicException;
use Psr\Log\LoggerInterface;
use Spora\Plugins\Concerns\StoresBinaryAssets;
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
 * Generates a short video clip via MiniMax's H3 multimodal video model.
 *
 * Four operations:
 *
 *   - `generate`       — submit a multimodal `content[]` payload (text + optional
 *     first/last-frame images + reference images / videos / audio), poll
 *     `/v2/query/video_generation/{task_id}` until `succeeded`, archive the
 *     MP4 via the Media Archive. The download URL is read directly from
 *     `task.content.url` on success — no second `/v1/files/retrieve` roundtrip.
 *
 *   - `resume`         — re-attach to a previously submitted task by `task_id`
 *     and continue polling. Used when `generate` returned `success: false` with
 *     `data.timed_out: true`.
 *
 *   - `enhance_prompt` — POST to `/v2/h3_context_ir` with the same `content[]`
 *     shape; the upstream returns a structured, semantically richer video
 *     prompt (retrievable from `task.content.prompt` when the task succeeds,
 *     identified by `task_type=h3_context_ir`). No video is produced.
 *
 *   - `regenerate`     — re-submit a previously generated H3 video's `content[]`
 *     to `/v2/video_regeneration` with `resolution: '2K'` and the original
 *     768P output appended as a `role=base_video` source. The LLM is
 *     responsible for passing back the exact `prompt` / `first_frame_image` /
 *     `last_frame_image` / `reference_*` arguments it used for the original
 *     `generate` call, plus `base_video_url` (the previous 768P output's
 *     `download_url` or `asset_url`). The tool does not persist the
 *     original `content[]` — the LLM is the source of truth.
 *
 * All four operations share {@see MiniMaxVideoPoller::pollUntilDone()} —
 * the only difference is the create endpoint and how the success envelope
 * is consumed.
 *
 * Class structure: the URL / scalar hygiene rules, content[] builder,
 * argument validators, HTTP submitter, and poll loop are split into
 * dedicated files so this class stays under Sonar's 20-method threshold
 * (S1448) and so the per-function cognitive complexity stays under the
 * 15 (S3776) threshold.
 */
#[Tool(
    name: 'video',
    description: 'Generate a short video clip via MiniMax H3 (multimodal: text + first/last-frame images + reference images / video clips / audio). Async; download URL valid briefly. Use `enhance_prompt` to enrich the prompt first, `resume` to re-attach to a timed-out task, and `regenerate` to upsample a finished 768P clip to 2K.',
    displayName: 'MiniMax Video',
    category: 'generation',
    icon: 'video',
)]
#[ToolOperation(name: 'generate', description: 'Submit a new H3 video task (text + optional image / video / audio references) and archive the result.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'resume', description: 'Continue polling a previously submitted task by id. Use when a previous `generate` returned `data.timed_out: true`.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'enhance_prompt', description: 'Send the same multimodal inputs to H3-Context-IR and return an enriched, structured prompt (no video is produced). Pass the returned prompt into a follow-up `generate` call for best results.', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'regenerate', description: 'Upsample a finished 768P H3 video to 2K by re-submitting the original content[] with the source clip as `base_video`. Requires a `task_id` from a previous `generate` call.', enabledByDefault: true, requiresApprovalByDefault: true)]
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
    description: 'Video model id. Only `MiniMax-H3` is supported by the v2 endpoint.',
    default: 'MiniMax-H3',
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
    description: 'Total wait window for H3 tasks (default: 900). 2K regeneration on a busy day can take 8–12 min.',
    default: '900',
)]
#[ToolSetting(
    key: 'submit_timeout_seconds',
    label: 'Submit timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the submit API call (MiniMax queues the task server-side; default: 120).',
    default: '120',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Text prompt describing the video. Camera-movement tags like `[Pan left]`, `[Push in]` are supported. Max 7000 characters (H3 cap). Required for `generate` and `enhance_prompt`; ignored by `resume`. `regenerate` requires the same `prompt` originally submitted (used to rebuild `content[]` from call arguments).',
    required: ['generate', 'enhance_prompt'],
    maximum: 7000,
)]
#[ToolParameter(
    name: 'first_frame_image',
    type: 'string',
    description: 'URL of the opening frame for image-to-video. Mutually exclusive with `last_frame_image` (use both for start-end-frame). H3 input caps: ≤30 MB, [256, 5760] px, aspect [0.4, 2.5]. `generate` / `enhance_prompt` only.',
    required: false,
)]
#[ToolParameter(
    name: 'last_frame_image',
    type: 'string',
    description: 'URL of the ending frame for start-end-frame image-to-video. Pairs with `first_frame_image`. Same input caps. `generate` / `enhance_prompt` only.',
    required: false,
)]
#[ToolParameter(
    name: 'reference_images',
    type: 'array',
    description: 'Subject/style reference images (reference-to-video mode). Up to 9. Mutually exclusive with `first_frame_image` / `last_frame_image` — pick image-to-video OR reference-to-video, not both.',
    required: false,
    items: ['type' => 'string'],
    maximum: 9,
)]
#[ToolParameter(
    name: 'reference_videos',
    type: 'array',
    description: 'Reference video clips (reference-to-video mode). Up to 3.',
    required: false,
    items: ['type' => 'string'],
    maximum: 3,
)]
#[ToolParameter(
    name: 'reference_audio',
    type: 'array',
    description: 'Reference audio tracks (reference-to-video mode). Up to 3. Must be paired with at least one image or video reference — H3 rejects audio-only `content[]`.',
    required: false,
    items: ['type' => 'string'],
    maximum: 3,
)]
#[ToolParameter(
    name: 'aspect_ratio',
    type: 'string',
    description: 'Output aspect ratio. `adaptive` is forced for image-to-video and is the default for reference-to-video. For text-to-video must be a concrete ratio (16:9, 9:16, etc.); `adaptive` falls back to 16:9.',
    required: false,
)]
#[ToolParameter(
    name: 'duration_seconds',
    type: 'integer',
    description: 'Clip length in seconds. Integer 4–15 (default 6). Decimals rejected.',
    required: false,
    minimum: 4,
    maximum: 15,
)]
#[ToolParameter(
    name: 'resolution',
    type: 'string',
    description: 'Output resolution. 768P (default; cheaper) or 2K. Regenerate locks to 2K.',
    required: false,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional filename stem for the Media Archive row (extension auto-appended). Sanitised; non-alphanumerics collapsed.',
    required: false,
)]
#[ToolParameter(
    name: 'action',
    type: 'string',
    description: 'Operation selector. Default `generate`. Other values: `resume`, `enhance_prompt`, `regenerate`.',
    required: false,
)]
#[ToolParameter(
    name: 'task_id',
    type: 'string',
    description: 'Required for `resume` and `regenerate` — the task_id returned by a previous `generate` (or `enhance_prompt`) call.',
    required: false,
)]
#[ToolParameter(
    name: 'base_video_url',
    type: 'string',
    description: 'For `regenerate`: the download URL of the previous 768P H3 video (the `download_url` / `asset_url` from the original `generate` response). Required for `regenerate`; ignored by other operations.',
    required: false,
)]
#[ToolParameter(
    name: 'poll_timeout_seconds',
    type: 'integer',
    description: 'Per-call override for `poll_timeout_seconds` setting. Useful for `resume` calls with a longer wait window.',
    required: false,
)]
final class MiniMaxVideoTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'video';
    protected const DEFAULT_MODEL   = 'MiniMax-H3';
    protected const QUALIFIED_NAME  = 'minimax:video';
    protected const TIMEOUT_SECONDS = 120;
    protected const TOOL_LABEL      = 'Video generation';

    public function __construct(
        \Spora\Services\ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        \Spora\Plugins\MiniMax\Support\MiniMaxLogWriter $logWriter,
        ?LoggerInterface $logger = null,
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
     * Multi-operation dispatcher.
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
            'generate'       => $this->generate($arguments, $agentId, $userId),
            'resume'         => $this->resume($arguments, $agentId, $userId),
            'enhance_prompt' => $this->enhancePrompt($arguments, $agentId, $userId),
            'regenerate'     => $this->regenerate($arguments, $agentId, $userId),
            default          => new ToolResult(false, "Unknown video operation: {$operation}. Expected 'generate', 'resume', 'enhance_prompt', or 'regenerate'."),
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
            'enhance_prompt' => "Enhance H3 prompt for: '{$prompt}'",
            'regenerate'     => "Regenerate H3 video at 2K for task: '{$taskId}'",
            'resume'         => "Resume video polling for task: '{$taskId}'",
            default          => "Generate video for prompt: '{$prompt}'",
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
            static fn(array $a) => MiniMaxVideoValidator::validateSubmitArguments($a),
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
            static fn(array $a) => MiniMaxVideoValidator::validateResumeArguments($a),
        );
    }

    /** @param array<string, mixed> $arguments */
    public function enhancePrompt(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            'Prompt enhancement',
            fn(MiniMaxToolContext $c) => $this->doEnhancePrompt($c, $arguments),
            static fn(array $a) => MiniMaxVideoValidator::validateSubmitArguments($a),
        );
    }

    /** @param array<string, mixed> $arguments */
    public function regenerate(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            static::TOOL_LABEL,
            fn(MiniMaxToolContext $c) => $this->doRegenerate($c, $arguments),
            static fn(array $a) => MiniMaxVideoValidator::validateRegenerateArguments($a),
        );
    }

    /**
     * Per-call work for the `generate` operation. Submits, polls,
     * retrieves the download URL from the poll response, archives.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doGenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $content = MiniMaxVideoContentBuilder::buildContentArray($arguments);
        $ratio   = MiniMaxVideoContentBuilder::resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;
        $resolution  = MiniMaxVideoContentBuilder::resolveResolution($arguments);
        $filenameRaw = isset($arguments['filename']) ? (string) $arguments['filename'] : '';

        $this->support->logger()?->debug('MiniMaxVideoTool: generate dispatched', [
            'mode'             => MiniMaxVideoContentBuilder::detectContentMode($content),
            'content_items'    => count($content),
            'duration'         => $duration,
            'resolution'       => $resolution,
            'ratio'            => $ratio,
            'prompt_len'       => mb_strlen((string) ($arguments['prompt'] ?? '')),
            'filename_supplied' => $filenameRaw !== '',
        ]);

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $taskId        = MiniMaxVideoSubmitter::submitTask(
            $ctx->client,
            $ctx->settings,
            $content,
            $duration,
            $resolution,
            $ratio,
            $submitTimeout,
        );

        $this->support->logger()?->info('MiniMaxVideoTool: generate submitted', [
            'task_id'       => $taskId,
            'duration'      => $duration,
            'resolution'    => $resolution,
            'ratio'         => $ratio,
            'content_items' => count($content),
        ]);

        $pollResult = $this->pollAndWait(
            $ctx,
            $arguments,
            $taskId,
            expectKind: 'generation',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments);
        }
        $finalResponse = $pollResult['data'];
        $downloadUrl   = (string) ($finalResponse['content']['url'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        return $this->archiveAndRender(
            $ctx,
            $downloadUrl,
            [
                'task_id'        => $taskId,
                'final_response' => $finalResponse,
                'duration'       => $duration,
                'resolution'     => $resolution,
                'prompt'         => trim((string) ($arguments['prompt'] ?? '')),
                'ratio'          => $ratio,
                'kind'           => 'generation',
                'filename_raw'   => $filenameRaw,
            ],
        );
    }

    /**
     * Per-call work for the `resume` operation. Polls an existing
     * task_id and archives on success.
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

        $pollResult = $this->pollAndWait(
            $ctx,
            $arguments,
            $taskId,
            expectKind: null,
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments);
        }
        $finalResponse = $pollResult['data'];
        $downloadUrl   = (string) ($finalResponse['content']['url'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        $taskKind = is_string($finalResponse['task_type'] ?? null) ? (string) $finalResponse['task_type'] : 'generation';
        if ($taskKind === 'h3_context_ir') {
            // The task is a prompt-enhancement job, not a video — `resume`
            // shouldn't be called on one in normal flow, but if it is,
            // surface the enhanced prompt rather than nothing.
            $prompt = (string) ($finalResponse['content']['prompt'] ?? '');
            return new ToolResult(true, "Enhanced prompt:\n\n{$prompt}", [
                'task_id'         => $taskId,
                'enhanced_prompt' => $prompt,
                'task_type'       => $taskKind,
            ]);
        }

        $duration   = isset($finalResponse['duration']) ? (int) $finalResponse['duration'] : 0;
        $resolution = (string) ($finalResponse['resolution'] ?? '');
        $ratio      = (string) ($finalResponse['ratio'] ?? '');

        return $this->archiveAndRender(
            $ctx,
            $downloadUrl,
            [
                'task_id'        => $taskId,
                'final_response' => $finalResponse,
                'duration'       => $duration,
                'resolution'     => $resolution,
                'prompt'         => '',
                'ratio'          => $ratio,
                'kind'           => $taskKind,
                'filename_raw'   => '',
            ],
        );
    }

    /**
     * Per-call work for the `enhance_prompt` operation. Submits to
     * `/v2/h3_context_ir`, polls, returns the enriched prompt as data.
     * No archive step — H3-Context-IR produces text, not media.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doEnhancePrompt(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $content = MiniMaxVideoContentBuilder::buildContentArray($arguments);
        $ratio   = MiniMaxVideoContentBuilder::resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;

        $this->support->logger()?->debug('MiniMaxVideoTool: enhance_prompt dispatched', [
            'mode'          => MiniMaxVideoContentBuilder::detectContentMode($content),
            'content_items' => count($content),
            'duration'      => $duration,
            'ratio'         => $ratio,
        ]);

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $taskId        = MiniMaxVideoSubmitter::submitEnhancePromptTask(
            $ctx->client,
            $ctx->settings,
            $content,
            $duration,
            $ratio,
            $submitTimeout,
        );

        $this->support->logger()?->info('MiniMaxVideoTool: enhance_prompt submitted', [
            'task_id'  => $taskId,
            'duration' => $duration,
        ]);

        $pollResult = $this->pollAndWait(
            $ctx,
            $arguments,
            $taskId,
            expectKind: 'h3_context_ir',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments);
        }
        $finalResponse  = $pollResult['data'];
        $enhancedPrompt = (string) ($finalResponse['content']['prompt'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        if ($enhancedPrompt === '') {
            return new ToolResult(false, "H3-Context-IR task succeeded (task_id={$taskId}) but the enhanced prompt was empty.", [
                'task_id'   => $taskId,
                'task_type' => 'h3_context_ir',
            ]);
        }

        return new ToolResult(
            true,
            "Enhanced prompt (task_id={$taskId}):\n\n{$enhancedPrompt}",
            [
                'task_id'         => $taskId,
                'enhanced_prompt' => $enhancedPrompt,
                'task_type'       => 'h3_context_ir',
                'usage'           => is_array($finalResponse['usage'] ?? null) ? $finalResponse['usage'] : null,
            ],
        );
    }

    /**
     * Per-call work for the `regenerate` operation. Re-builds the
     * original generation's `content[]` from the same arguments the
     * LLM passed to `generate` (the agent has them in its context),
     * appends the previous 768P output as `base_video`, and submits
     * to `/v2/video_regeneration`.
     *
     * The tool does NOT validate that the rebuilt `content[]` matches
     * what originally generated the source — H3 upstream will return
     * 400 if it doesn't. The LLM is the source of truth; the skill
     * spells this out.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doRegenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $taskId       = trim((string) ($arguments['task_id'] ?? ''));
        $baseVideoUrl = trim((string) ($arguments['base_video_url'] ?? ''));

        $this->support->logger()?->debug('MiniMaxVideoTool: regenerate started', [
            'source_task_id' => $taskId,
            'base_video_url' => $baseVideoUrl,
        ]);

        $content = MiniMaxVideoContentBuilder::buildContentArray($arguments);
        $ratio   = MiniMaxVideoContentBuilder::resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $newTaskId     = MiniMaxVideoSubmitter::submitRegenerationTask(
            $ctx->client,
            $ctx->settings,
            $content,
            $baseVideoUrl,
            $submitTimeout,
        );

        $this->support->logger()?->info('MiniMaxVideoTool: regenerate submitted', [
            'source_task_id' => $taskId,
            'new_task_id'    => $newTaskId,
            'content_items'  => count($content),
            'ratio'          => $ratio,
            'duration'       => $duration,
        ]);

        $pollResult = $this->pollAndWait(
            $ctx,
            $arguments,
            $newTaskId,
            expectKind: 'regeneration',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments);
        }
        $finalResponse = $pollResult['data'];
        $downloadUrl   = (string) ($finalResponse['content']['url'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        return $this->archiveAndRender(
            $ctx,
            $downloadUrl,
            [
                'task_id'        => $newTaskId,
                'final_response' => $finalResponse,
                'duration'       => $duration,
                'resolution'     => '2K',
                'prompt'         => trim((string) ($arguments['prompt'] ?? '')),
                'ratio'          => $ratio,
                'kind'           => 'regeneration',
                'filename_raw'   => '',
            ],
        );
    }

    /**
     * Resolve poll-loop settings and delegate to {@see MiniMaxVideoPoller}.
     *
     * @param  array<string, mixed> $arguments
     * @return array{success: bool, data?: array<string, mixed>}
     */
    private function pollAndWait(
        MiniMaxToolContext $ctx,
        array $arguments,
        string $taskId,
        ?string $expectKind,
    ): array {
        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $ctx->settings, 10);

        $overrideTimeout = isset($arguments['poll_timeout_seconds'])
            ? (int) $arguments['poll_timeout_seconds']
            : 0;
        $settingTimeout  = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 900);
        $pollTimeout     = $overrideTimeout > 0 ? $overrideTimeout : $settingTimeout;

        return MiniMaxVideoPoller::pollUntilDone(
            $ctx->client,
            $taskId,
            $pollTimeout,
            $pollInterval,
            $this->support->logger(),
            $expectKind,
        );
    }

    /**
     * Format a timed-out poll envelope into a failed ToolResult that
     * carries the task_id and the original submission metadata so the
     * LLM can `resume` on a subsequent turn without losing context.
     *
     * @param  array<string, mixed>       $err          poll-loop timeout envelope
     * @param  array<string, mixed>       $arguments    original call's args
     */
    private function timedOutResult(array $err, array $arguments): ToolResult
    {
        $filenameRaw = isset($arguments['filename']) ? (string) $arguments['filename'] : '';
        return new ToolResult(false, $err['content'], [
            'task_id'           => $err['task_id'],
            'status'            => $err['status'],
            'timed_out'         => $err['timed_out'],
            'prompt'            => trim((string) ($arguments['prompt'] ?? '')),
            'first_frame_image' => trim((string) ($arguments['first_frame_image'] ?? '')),
            'last_frame_image'  => trim((string) ($arguments['last_frame_image'] ?? '')),
            'reference_images'  => MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_images'] ?? null),
            'reference_videos'  => MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_videos'] ?? null),
            'reference_audio'   => MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_audio'] ?? null),
            'aspect_ratio'      => trim((string) ($arguments['aspect_ratio'] ?? '')),
            'duration_seconds'  => (int) ($arguments['duration_seconds'] ?? 6),
            'resolution'        => trim((string) ($arguments['resolution'] ?? '')),
            'filename'          => $filenameRaw,
        ]);
    }

    /**
     * Ingest the H3 download URL into the Media Archive and return a
     * rendered ToolResult with the `<video>` element + trailing
     * verbatim-echo instruction.
     *
     * v2 doesn't return width/height in the success envelope (the v1
     * `video_width` / `video_height` fields are gone), so the embed
     * renders without explicit dimensions — the chat UI auto-sizes
     * the player.
     *
     * @param  array{
     *     task_id: string,
     *     final_response: array<string, mixed>,
     *     duration: int,
     *     resolution: string,
     *     prompt: string,
     *     ratio: string,
     *     kind: string,
     *     filename_raw: string,
     * } $taskOutcome
     */
    private function archiveAndRender(
        MiniMaxToolContext $ctx,
        string $downloadUrl,
        array $taskOutcome,
    ): ToolResult {
        if ($downloadUrl === '') {
            return new ToolResult(false, "MiniMax H3 video succeeded (task_id={$taskOutcome['task_id']}) but the response did not include a download URL.");
        }

        $this->support->logger()?->debug('MiniMaxVideoTool: archiving result', [
            'task_id'      => $taskOutcome['task_id'],
            'kind'         => $taskOutcome['kind'],
            'download_url' => $downloadUrl,
        ]);

        $archiveAsset = $this->ingestIntoArchive($ctx, $taskOutcome, $downloadUrl);

        // `archived` distinguishes the two states the trailing
        // instruction has to acknowledge — the rendered `<video>` is
        // served by the Media Archive, or it isn't.
        $archived = $archiveAsset !== null
            && $archiveAsset->asset_url !== ''
            && !str_starts_with($archiveAsset->asset_url, 'data:');
        $archiveUrl = $archived ? $archiveAsset->asset_url : null;
        $embedUrl   = $archiveUrl ?? $downloadUrl;
        $sizeNote   = $archiveUrl !== null ? '' : ' (URL valid briefly — download promptly)';

        $kind      = $taskOutcome['kind'];
        $kindLabel = match ($kind) {
            'regeneration' => 'Regenerated video',
            'generation'   => 'Generated video',
            default        => 'H3 video',
        };

        $renderInstruction = $archived
            ? "Echo the `<video>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp4` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`."
            : "Echo the `<video>` element above verbatim — its `src` is the upstream MiniMax CDN URL (valid briefly); the Media Archive plugin isn't installed or this file was rejected, so the URL isn't rewritten to a long-lived `/api/v1/assets/...` path. Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.";

        $ratioLine  = $taskOutcome['ratio'] !== '' ? " ({$taskOutcome['ratio']})" : '';
        $promptLine = $taskOutcome['prompt'] !== '' ? " for prompt: \"{$taskOutcome['prompt']}\"" : '';
        $content    = "{$kindLabel}{$ratioLine}{$promptLine}\n\n"
            . MediaEmbed::videoFromUrl($embedUrl) . "\n\n"
            . "task_id: {$taskOutcome['task_id']}  resolution: {$taskOutcome['resolution']}  duration: {$taskOutcome['duration']}s{$sizeNote}"
            . "\n\n" . $renderInstruction;

        return new ToolResult(true, $content, [
            'task_id'      => $taskOutcome['task_id'],
            'download_url' => $downloadUrl,
            'asset_url'    => $embedUrl,
            'duration'     => $taskOutcome['duration'],
            'resolution'   => $taskOutcome['resolution'] !== '' ? $taskOutcome['resolution'] : null,
            'ratio'        => $taskOutcome['ratio'] !== '' ? $taskOutcome['ratio'] : null,
            'task_type'    => $kind,
        ]);
    }

    /**
     * Try to ingest the H3 download URL into the Media Archive. Returns
     * `null` on any failure — caller falls back to the upstream CDN URL.
     *
     * @param  array{
     *     task_id: string,
     *     prompt: string,
     *     duration: int,
     *     filename_raw: string,
     *     ...,
     * } $taskOutcome
     */
    private function ingestIntoArchive(MiniMaxToolContext $ctx, array $taskOutcome, string $downloadUrl): ?\Spora\Models\MediaAsset
    {
        try {
            return $this->mediaArchive()->ingest(new MediaIngestRequest(
                url: $downloadUrl,
                agentId: $ctx->agentId,
                pluginSlug: 'minimax',
                toolName: 'video',
                prompt: $taskOutcome['prompt'],
                durationSeconds: (float) $taskOutcome['duration'],
                filename: self::resolveFilename(
                    $taskOutcome['filename_raw'] !== '' ? $taskOutcome['filename_raw'] : null,
                    $taskOutcome['prompt'] !== '' ? $taskOutcome['prompt'] : 'h3-video',
                    'minimax-video',
                    'mp4',
                ),
            ));
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (video)', [
                'exception' => $e,
                'url'       => $downloadUrl,
            ]);
            return null;
        }
    }
}
