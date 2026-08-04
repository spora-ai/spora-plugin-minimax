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
 *     768P output appended as a `role=base_video` source. The original
 *     `content[]` is persisted to `minimax_generation_log.submitted_content`
 *     on `generate` success and looked up by `minimax_task_id` here.
 *
 * All four operations share `pollUntilDone()` — the only difference is the
 * create endpoint and how the success envelope is consumed.
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
    default: self::DEFAULT_MODEL,
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
    description: 'Text prompt describing the video. Camera-movement tags like `[Pan left]`, `[Push in]` are supported. Max 7000 characters (H3 cap). Required for `generate` and `enhance_prompt`; ignored by `resume` and `regenerate` (the latter replays the stored `content[]`).',
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
    description: 'Reference video clips (reference-to-video mode). Up to 3. Each: MP4/MOV, H.264/H.265, ≤50 MB, [2, 15] s, [256, 5760] px. `generate` / `enhance_prompt` only.',
    required: false,
    items: ['type' => 'string'],
    maximum: 3,
)]
#[ToolParameter(
    name: 'reference_audio',
    type: 'array',
    description: 'Reference audio clips (reference-to-video mode). Up to 3. Each: WAV/MP3, ≤15 MB, [2, 15] s. MUST be accompanied by an image or video input (H3 rejects audio-only `content[]`). `generate` / `enhance_prompt` only.',
    required: false,
    items: ['type' => 'string'],
    maximum: 3,
)]
#[ToolParameter(
    name: 'aspect_ratio',
    type: 'string',
    description: 'Aspect ratio. Required for text-only `generate` / `enhance_prompt` (cannot be `adaptive`). Auto-forced to `adaptive` whenever any image / video / audio reference is supplied (H3 derives the ratio from the input). Allowed: adaptive, 21:9, 16:9, 4:3, 1:1, 3:4, 9:16.',
    required: false,
    enum: self::ASPECT_RATIOS,
    default: '16:9',
)]
#[ToolParameter(
    name: 'duration_seconds',
    type: 'integer',
    description: 'Target video duration in seconds. Integer, 4–15 inclusive. `generate` / `enhance_prompt` only.',
    required: false,
    minimum: 4,
    maximum: 15,
    default: 6,
)]
#[ToolParameter(
    name: 'resolution',
    type: 'string',
    description: 'Output resolution. One of `768P`, `2K`. `resume` accepts it (preferred) so a timed-out `generate` can be replayed verbatim.',
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
    description: 'The MiniMax task id from a previous `generate` (or `enhance_prompt`) call. Required for `resume` and `regenerate`.',
    required: ['resume', 'regenerate'],
)]
#[ToolParameter(
    name: 'base_video_url',
    type: 'string',
    description: 'For `regenerate`: the download URL of the previous 768P H3 video (the `download_url` / `asset_url` from the original `generate` response). Required for `regenerate`; ignored by other operations.',
    required: ['regenerate'],
)]
final class MiniMaxVideoTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER         = 'video';
    protected const DEFAULT_MODEL    = 'MiniMax-H3';
    protected const QUALIFIED_NAME   = 'minimax:video';
    protected const TIMEOUT_SECONDS  = 120;
    protected const TOOL_LABEL       = 'Video generation';

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
     * Per-poll HTTP timeout. Bounds the single `GET /v2/query/...`
     * request so a stalled probe can't outlive the loop's overall
     * deadline — without this, a stalled request makes the
     * `timed_out` envelope never reachable even when wall-clock time
     * has already exceeded `poll_timeout_seconds`.
     */
    protected const POLL_REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Allowed resolution values under H3. MiniMax's v2 endpoint accepts
     * only these two; uppercase `P` literal on `768P`.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['768P', '2K'];

    /**
     * Allowed aspect-ratio values under H3. `adaptive` is valid only for
     * image-to-video and reference-to-video modes (image-driven). For
     * text-to-video `adaptive` is rejected upstream — the resolver in
     * {@see resolveAspectRatio()} handles that mode-aware enforcement.
     *
     * @var list<string>
     */
    public const ASPECT_RATIOS = ['adaptive', '21:9', '16:9', '4:3', '1:1', '3:4', '9:16'];

    /**
     * Aspect ratios valid for text-to-video mode. Excludes `adaptive`,
     * which H3 rejects for t2v (the spec: "Text-to-video (t2va): ratio is
     * required and cannot be `adaptive`"). Used by the resolver to fall
     * back to a safe concrete ratio when the LLM supplies `adaptive`
     * with text-only content.
     *
     * @var list<string>
     */
    private const TEXT_ONLY_ASPECT_RATIOS = ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'];

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
            fn(array $a) => $this->validateSubmitArguments($a),
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
            fn(array $a) => $this->validateSubmitArguments($a),
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
            fn(array $a) => $this->validateRegenerateArguments($a),
        );
    }

    /**
     * Validate `generate` and `enhance_prompt` inputs. Both operations
     * share the same `content[]`-building path, so they share the same
     * input validation too.
     *
     * `resume` doesn't need it (all fields are upstream-fixed at submit
     * time). `regenerate` doesn't need it (the original `content[]` is
     * replayed verbatim from the log row).
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateSubmitArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            $errors[] = 'Prompt cannot be empty.';
        }
        if (mb_strlen($prompt) > 7000) {
            $errors[] = 'Prompt exceeds the 7000-character H3 limit.';
        }

        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 0;
        if ($duration < 4 || $duration > 15) {
            $errors[] = 'duration_seconds must be an integer between 4 and 15.';
        }

        $resolution = trim((string) ($arguments['resolution'] ?? ''));
        if ($resolution !== '' && !in_array($resolution, self::RESOLUTIONS, true)) {
            $errors[] = 'resolution must be "768P" or "2K" (uppercase P on 768P).';
        }

        // Build the content[] to surface mode + limit errors early.
        $contentErrors = $this->collectContentErrors($arguments);
        if ($contentErrors !== []) {
            $errors = array_merge($errors, $contentErrors);
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Validate the `resume` operation's inputs — just the `task_id`
     * is required. Prompt / duration / resolution are ignored (the
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
     * Validate the `regenerate` operation's inputs. Needs `task_id`
     * and `base_video_url` (the URL of the previous 768P source).
     * Resolution is locked to `2K` (the only value the v2
     * regeneration endpoint accepts).
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateRegenerateArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $taskId = trim((string) ($arguments['task_id'] ?? ''));
        if ($taskId === '') {
            $errors[] = 'task_id is required for the regenerate operation (the original `generate` call\'s task id).';
        }

        $baseVideoUrl = trim((string) ($arguments['base_video_url'] ?? ''));
        if ($baseVideoUrl === '') {
            $errors[] = 'base_video_url is required for the regenerate operation (the previous 768P output\'s download_url or asset_url).';
        } elseif (!$this->isAcceptableUrl($baseVideoUrl)) {
            $errors[] = 'base_video_url must be http(s):// or mm_file:// — data: URIs are rejected (the 64 MB request body cap can\'t carry inline base64).';
        }

        $resolution = trim((string) ($arguments['resolution'] ?? '2K'));
        if ($resolution !== '' && $resolution !== '2K') {
            $errors[] = 'regenerate currently only supports resolution "2K" (the v2 regeneration endpoint upsamples 768P sources to 2K).';
        }

        if ($errors !== []) {
            return new ToolResult(false, implode(' ', $errors));
        }
        return null;
    }

    /**
     * Inspect `arguments` for content[] violations. Returns a list of
     * human-readable errors (empty when input is valid).
     *
     * Four families of checks:
     *
     *   1. **Mode exclusivity** — H3 forbids mixing `first_frame` /
     *      `last_frame` with `reference_*` roles in the same `content[]`.
     *      The validator here only sees the LLM's flat input, so we
     *      derive the intended mode from the presence of any frame image
     *      vs. any reference and reject combinations.
     *
     *   2. **Per-mode counts** — frame images: ≤2 (one each). References:
     *      ≤9 images, ≤3 videos, ≤3 audio. Per the v2 spec tables.
     *
     *   3. **Audio-needs-image rule** — `reference_audio` must be
     *      accompanied by an image or video input. H3 rejects audio-only
     *      `content[]` ("cannot be input alone").
     *
     *   4. **URL hygiene** — every URL must be `http://`, `https://`, or
     *      `mm_file://`. `data:` URIs are rejected here even though the
     *      spec allows them, because the request body is capped at 64 MB
     *      and inline base64 inflates by ~33% — any non-trivial base64
     *      payload will exceed the cap and 400. Surface the rejection
     *      client-side instead of burning quota.
     *
     * @param  array<string, mixed> $arguments
     * @return list<string>
     */
    private function collectContentErrors(array $arguments): array
    {
        $errors   = [];
        $first    = trim((string) ($arguments['first_frame_image'] ?? ''));
        $last     = trim((string) ($arguments['last_frame_image'] ?? ''));
        $refImgs  = $this->normaliseStringList($arguments['reference_images'] ?? null);
        $refVids  = $this->normaliseStringList($arguments['reference_videos'] ?? null);
        $refAud   = $this->normaliseStringList($arguments['reference_audio'] ?? null);

        $hasFrames      = $first !== '' || $last !== '';
        $hasReferences  = $refImgs !== [] || $refVids !== [] || $refAud !== [];

        // 1. Mode exclusivity.
        if ($hasFrames && $hasReferences) {
            $errors[] = 'image-to-video (first_frame_image / last_frame_image) and reference-to-video (reference_*) are mutually exclusive — pick one mode per call.';
        }

        // 2a. Frame counts.
        if ($first === '' && $last !== '') {
            $errors[] = 'last_frame_image requires first_frame_image to be set (H3 pairs them).';
        }

        // 2b. Reference counts.
        if (count($refImgs) > 9) {
            $errors[] = 'reference_images accepts at most 9 entries.';
        }
        if (count($refVids) > 3) {
            $errors[] = 'reference_videos accepts at most 3 entries.';
        }
        if (count($refAud) > 3) {
            $errors[] = 'reference_audio accepts at most 3 entries.';
        }

        // 3. Audio-needs-image.
        if ($refAud !== [] && $refImgs === [] && $refVids === [] && !$hasFrames) {
            $errors[] = 'reference_audio must be accompanied by an image or video input (H3 rejects audio-only content[]).';
        }

        // 4. URL hygiene across all asset lists.
        $allUrls = array_values(array_filter([$first, $last], static fn(string $u): bool => $u !== ''));
        $allUrls = array_merge($allUrls, $refImgs, $refVids, $refAud);
        foreach ($allUrls as $url) {
            if (!$this->isAcceptableUrl($url)) {
                $errors[] = "media URL must be http(s):// or mm_file:// (data: URIs are rejected — the 64 MB request body cap can't carry inline base64): '{$url}'.";
            }
        }

        return $errors;
    }

    /**
     * Accept `http://`, `https://`, or `mm_file://` URLs. Reject `data:`
     * (per {@see collectContentErrors()}) and any other scheme.
     */
    private function isAcceptableUrl(string $url): bool
    {
        return str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, 'mm_file://');
    }

    /**
     * Coerce a possibly-mixed user input into a clean list of non-empty strings.
     * Accepts:
     *   - null / missing → []
     *   - list<string>   → filtered to non-empty entries, trimmed
     *   - string         → wrapped as a single-element list (some callers
     *                       pass "url1,url2" — we don't try to split on commas
     *                       since URL parsing inside commas is brittle)
     *
     * @param  mixed       $raw
     * @return list<string>
     */
    private function normaliseStringList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            return $trimmed === '' ? [] : [$trimmed];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Build the H3 `content[]` array from the LLM's flat arguments.
     * Always emits exactly one `text` item (H3 requires a non-empty
     * prompt) and optionally appends frame / reference items with
     * the right `role`.
     *
     * Frame images are added in order (first then last) to keep the
     * upstream's expected order stable; references are appended in
     * the LLM-supplied order.
     *
     * @param  array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    private function buildContentArray(array $arguments): array
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $first  = trim((string) ($arguments['first_frame_image'] ?? ''));
        $last   = trim((string) ($arguments['last_frame_image'] ?? ''));

        $content = [['type' => 'text', 'text' => $prompt]];

        if ($first !== '') {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $first],
                'role'      => 'first_frame',
            ];
        }
        if ($last !== '') {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $last],
                'role'      => 'last_frame',
            ];
        }

        foreach ($this->normaliseStringList($arguments['reference_images'] ?? null) as $url) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $url],
                'role'      => 'reference_image',
            ];
        }
        foreach ($this->normaliseStringList($arguments['reference_videos'] ?? null) as $url) {
            $content[] = [
                'type'      => 'video_url',
                'video_url' => ['url' => $url],
                'role'      => 'reference_video',
            ];
        }
        foreach ($this->normaliseStringList($arguments['reference_audio'] ?? null) as $url) {
            $content[] = [
                'type'      => 'audio_url',
                'audio_url' => ['url' => $url],
                'role'      => 'reference_audio',
            ];
        }

        return $content;
    }

    /**
     * Classify a built `content[]` into a short mode label for
     * debug logging. One of `text_only`, `i2v_first_frame`,
     * `i2v_first_last_frame`, `r2v`. Anything else falls through to
     * `mixed` so the log line still surfaces the shape.
     *
     * @param  array<int, array<string, mixed>> $content
     */
    private function detectContentMode(array $content): string
    {
        $roles = [];
        foreach ($content as $item) {
            if (isset($item['role']) && is_string($item['role'])) {
                $roles[] = $item['role'];
            }
        }
        $hasFirst = in_array('first_frame', $roles, true);
        $hasLast  = in_array('last_frame', $roles, true);
        $hasRef   = (bool) array_intersect($roles, ['reference_image', 'reference_video', 'reference_audio']);

        if ($hasFirst && $hasLast) {
            return 'i2v_first_last_frame';
        }
        if ($hasFirst) {
            return 'i2v_first_frame';
        }
        if ($hasRef) {
            return 'r2v';
        }
        return 'text_only';
    }

    /**
     * Resolve the effective aspect ratio to send upstream.
     *
     * H3 mode-aware rules (per the v2 spec):
     *
     *   - **Text-to-video** (`content[]` contains only `text`): ratio is
     *     required and cannot be `adaptive`. If the LLM supplied
     *     `adaptive`, fall back to `16:9`. If the LLM supplied nothing
     *     valid, also fall back to `16:9`.
     *   - **Image-to-video** (`content[]` has first_frame / last_frame):
     *     ratio is always `adaptive`. Any concrete ratio supplied by
     *     the LLM is silently ignored by upstream, so we just force it
     *     and save the round-trip interpretation.
     *   - **Reference-to-video** (`content[]` has reference_*): ratio is
     *     optional and defaults to `adaptive`. LLM may also pass a
     *     concrete ratio.
     *
     * `resume` doesn't need this (it polls only — the original submit
     * already happened). `regenerate` reuses it (it rebuilds content[]
     * from the same arguments).
     *
     * @param  array<int, array<string, mixed>> $content
     */
    private function resolveAspectRatio(array $content, string $llmSupplied): string
    {
        $hasNonText = false;
        foreach ($content as $item) {
            $type = is_string($item['type'] ?? null) ? $item['type'] : '';
            if ($type !== 'text') {
                $hasNonText = true;
                break;
            }
        }

        if ($hasNonText) {
            // i2v: H3 silently ignores any non-`adaptive` value when content[]
            // has a first_frame / last_frame, so we force `adaptive` server-side
            // (saves a round-trip interpretation). r2v: defaults to `adaptive`
            // but LLM-supplied concrete ratios are honoured.
            $hasFrameImages = false;
            foreach ($content as $item) {
                $role = is_string($item['role'] ?? null) ? $item['role'] : '';
                if ($role === 'first_frame' || $role === 'last_frame') {
                    $hasFrameImages = true;
                    break;
                }
            }

            if ($hasFrameImages) {
                $resolved = 'adaptive';
            } else {
                $concrete = in_array($llmSupplied, self::TEXT_ONLY_ASPECT_RATIOS, true);
                $resolved = $concrete ? $llmSupplied : 'adaptive';
            }

            $this->support->logger()?->debug('MiniMaxVideoTool: aspect ratio resolved (non-text content)', [
                'mode'         => $this->detectContentMode($content),
                'llm_supplied' => $llmSupplied,
                'resolved'     => $resolved,
            ]);
            return $resolved;
        }

        // t2v: must be concrete; reject `adaptive` by falling back.
        if ($llmSupplied !== '' && in_array($llmSupplied, self::TEXT_ONLY_ASPECT_RATIOS, true)) {
            $this->support->logger()?->debug('MiniMaxVideoTool: aspect ratio resolved (text-only)', [
                'mode'         => 'text_only',
                'llm_supplied' => $llmSupplied,
                'resolved'     => $llmSupplied,
            ]);
            return $llmSupplied;
        }
        $this->support->logger()?->debug('MiniMaxVideoTool: aspect ratio resolved (text-only fallback)', [
            'mode'         => 'text_only',
            'llm_supplied' => $llmSupplied,
            'resolved'     => '16:9',
            'reason'       => 't2v requires a concrete ratio; `adaptive` or invalid input defaults to 16:9',
        ]);
        return '16:9';
    }

    /**
     * Per-call work for the `generate` operation. Submits, polls,
     * retrieves the download URL from the poll response, archives.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doGenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $content = $this->buildContentArray($arguments);
        $ratio   = $this->resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;
        $resolution  = $this->resolveResolution($arguments, $ctx->settings);
        $filenameRaw = isset($arguments['filename']) ? (string) $arguments['filename'] : '';

        $this->support->logger()?->debug('MiniMaxVideoTool: generate dispatched', [
            'mode'           => $this->detectContentMode($content),
            'content_items'  => count($content),
            'duration'       => $duration,
            'resolution'     => $resolution,
            'ratio'          => $ratio,
            'prompt_len'     => mb_strlen((string) ($arguments['prompt'] ?? '')),
            'filename_supplied' => $filenameRaw !== '',
        ]);

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);

        $taskId = $this->submitTask(
            $client,
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

        $pollResult = $this->pollUntilDone(
            $client,
            $taskId,
            $ctx,
            $arguments,
            expectKind: 'generation',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments, $ctx->settings);
        }
        $finalResponse = $pollResult['data'];
        $downloadUrl   = (string) ($finalResponse['content']['url'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        return $this->archiveAndRender(
            $ctx,
            $arguments,
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

        $pollResult = $this->pollUntilDone(
            $ctx->client,
            $taskId,
            $ctx,
            $arguments,
            expectKind: null,
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments, $ctx->settings);
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
            $arguments,
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
        $content  = $this->buildContentArray($arguments);
        $ratio    = $this->resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;

        $this->support->logger()?->debug('MiniMaxVideoTool: enhance_prompt dispatched', [
            'mode'          => $this->detectContentMode($content),
            'content_items' => count($content),
            'duration'      => $duration,
            'ratio'         => $ratio,
        ]);

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $taskId = $this->submitEnhancePromptTask($client = $ctx->client, $ctx->settings, $content, $duration, $ratio, $submitTimeout);

        $this->support->logger()?->info('MiniMaxVideoTool: enhance_prompt submitted', [
            'task_id' => $taskId,
            'duration' => $duration,
        ]);

        $pollResult = $this->pollUntilDone(
            $client,
            $taskId,
            $ctx,
            $arguments,
            expectKind: 'h3_context_ir',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments, $ctx->settings);
        }
        $finalResponse = $pollResult['data'];
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
     * `submitRegenerationTask()` is responsible for the content
     * integrity check (H3 rejects regenerations whose `content` doesn't
     * match what generated the source). We don't compare against a
     * persisted log row — we trust the LLM to pass back the same
     * arguments it used for `generate`. The skill spells this out.
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

        $content  = $this->buildContentArray($arguments);
        $ratio    = $this->resolveAspectRatio(
            $content,
            trim((string) ($arguments['aspect_ratio'] ?? '16:9')),
        );
        $durationRaw = $arguments['duration_seconds'] ?? 6;
        $duration    = is_numeric($durationRaw) ? (int) $durationRaw : 6;

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $newTaskId = $this->submitRegenerationTask(
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

        $pollResult = $this->pollUntilDone(
            $ctx->client,
            $newTaskId,
            $ctx,
            $arguments,
            expectKind: 'regeneration',
        );
        if (!$pollResult['success']) {
            return $this->timedOutResult($pollResult['data'], $arguments, $ctx->settings);
        }
        $finalResponse = $pollResult['data'];
        $downloadUrl   = (string) ($finalResponse['content']['url'] ?? '');

        $this->support->logSuccess($ctx, $finalResponse);

        return $this->archiveAndRender(
            $ctx,
            $arguments,
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
     * Resolve the effective resolution. Only `768P` and `2K` are valid
     * under H3; no per-model matrix.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $settings
     */
    private function resolveResolution(array $arguments, array $settings): string
    {
        $supplied = trim((string) ($arguments['resolution'] ?? ''));
        if ($supplied !== '' && in_array($supplied, self::RESOLUTIONS, true)) {
            return $supplied;
        }
        // Default to 768P — cheaper than 2K, and the only resolution the
        // regeneration endpoint accepts as source. Operators can pick 2K
        // for higher-quality first-pass.
        return '768P';
    }

    /**
     * Build and POST the v2 `video_generation` body.
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    private function submitTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        int $duration,
        string $resolution,
        string $ratio,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'      => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'    => $content,
            'duration'   => $duration,
            'resolution' => $resolution,
            'ratio'      => $ratio,
        ];

        return $this->postAndExtractTaskId($client, '/v2/video_generation', $body, $timeoutSeconds);
    }

    /**
     * Build and POST the v2 `h3_context_ir` body. Same shape minus
     * `resolution` (H3-Context-IR returns a prompt, not a video, so
     * resolution doesn't apply).
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    private function submitEnhancePromptTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        int $duration,
        string $ratio,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'    => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'  => $content,
            'duration' => $duration,
            'ratio'    => $ratio,
        ];

        return $this->postAndExtractTaskId($client, '/v2/h3_context_ir', $body, $timeoutSeconds);
    }

    /**
     * Build and POST the v2 `video_regeneration` body. Per the spec,
     * `content` must reproduce the original generation's `content[]`
     * verbatim (the `text` is the FINAL prompt sent to the model, not
     * the user's original pre-Context-IR prompt) and append exactly
     * one `base_video` source.
     *
     * Only `resolution: '2K'` is supported upstream.
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    private function submitRegenerationTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        string $baseVideoUrl,
        int $timeoutSeconds,
    ): string {
        if ($baseVideoUrl === '') {
            throw new MiniMaxApiException(
                'regenerate: the original generate task\'s download URL is missing — the log row may have been written by an older plugin version that didn\'t capture the URL.',
                0,
            );
        }
        $content[] = [
            'type'      => 'video_url',
            'video_url' => ['url' => $baseVideoUrl],
            'role'      => 'base_video',
        ];

        $body = [
            'model'      => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'    => $content,
            'resolution' => '2K',
        ];

        return $this->postAndExtractTaskId($client, '/v2/video_regeneration', $body, $timeoutSeconds);
    }

    /**
     * POST a body to one of the v2 video endpoints and extract the
     * returned `task_id`. Synthetic {@see MiniMaxApiException} on
     * missing id keeps the shared error envelope consistent with the
     * rest of the plugin.
     *
     * @param  array<string, mixed> $body
     */
    private function postAndExtractTaskId(
        MiniMaxHttpClient $client,
        string $path,
        array $body,
        int $timeoutSeconds,
    ): string {
        $this->support->logger()?->debug('MiniMaxVideoTool: POST submit', [
            'path'           => $path,
            'model'          => $body['model'] ?? null,
            'resolution'     => $body['resolution'] ?? null,
            'duration'       => $body['duration'] ?? null,
            'ratio'          => $body['ratio'] ?? null,
            'content_items'  => is_array($body['content'] ?? null) ? count($body['content']) : 0,
            'timeout'        => $timeoutSeconds,
        ]);

        $response     = $client->postJson($path, $body, timeoutSeconds: $timeoutSeconds);
        $taskId       = is_string($response['task_id'] ?? null) ? $response['task_id'] : '';
        if ($taskId === '') {
            $this->support->logger()?->error('MiniMaxVideoTool: submit returned no task_id', [
                'path'     => $path,
                'response' => $response,
            ]);
            throw new MiniMaxApiException("MiniMax returned no task_id from {$path}.", 0, $response);
        }
        $this->support->logger()?->debug('MiniMaxVideoTool: submit accepted', [
            'path'    => $path,
            'task_id' => $taskId,
        ]);
        return $taskId;
    }

    /**
     * Poll the shared H3 task query endpoint until the task reaches a
     * terminal state. Works for `generation`, `h3_context_ir`, and
     * `regeneration` task types — the caller decides what to do with
     * the success envelope via `expectKind` and `task_type`.
     *
     * Terminal states:
     *   - `succeeded` → returns `['success' => true, 'data' => <task>]`.
     *   - `failed`    → throws {@see MiniMaxApiException} with the
     *                   upstream `error.message`.
     *   - `cancelled` → throws {@see MiniMaxApiException}.
     *
     * Non-terminal:
     *   - Timeout     → returns `['success' => false, 'data' => <envelope>]`
     *                   so the caller can surface a resume-able failure.
     *
     * `expectKind` is informational only — the caller's `doGenerate` /
     * `doEnhancePrompt` / `doRegenerate` already knows which task type
     * it submitted. A mismatch between submit type and `task_type` on
     * success is logged but not rejected; the upstream shouldn't
     * re-classify.
     *
     * @param  ?string             $expectKind  Optional expected `task_type` for sanity-checking.
     * @return array{success: bool, data?: array<string, mixed>}
     */
    private function pollUntilDone(
        MiniMaxHttpClient $client,
        string $taskId,
        MiniMaxToolContext $ctx,
        array $arguments,
        ?string $expectKind,
    ): array {
        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $ctx->settings, 10);

        $overrideTimeout = isset($arguments['poll_timeout_seconds'])
            ? (int) $arguments['poll_timeout_seconds']
            : 0;
        $settingTimeout  = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 900);
        $pollTimeout     = $overrideTimeout > 0 ? $overrideTimeout : $settingTimeout;

        $intervalSeconds = max(self::MIN_POLL_INTERVAL_SECONDS, min(self::MAX_POLL_INTERVAL_SECONDS, $pollInterval));
        $deadline        = microtime(true) + max(10, $pollTimeout);

        $this->support->logger()?->info('MiniMaxVideoTool: poll loop started', [
            'task_id'      => $taskId,
            'interval'     => $intervalSeconds,
            'poll_timeout' => $pollTimeout,
            'expect_kind'  => $expectKind,
        ]);

        while (true) {
            if (microtime(true) >= $deadline) {
                return [
                    'success' => false,
                    'data' => [
                        'task_id'   => $taskId,
                        'status'    => 'still_running',
                        'timed_out' => true,
                        'content'   => sprintf(
                            'H3 task did not finish within %ds (task_id=%s). The task is still running on MiniMax\'s side and is billable. '
                            . 'Increase `poll_timeout_seconds` and call `minimax_video(action: "resume", task_id: "%s")` to keep waiting, '
                            . 'or abandon it and accept the billed quota.',
                            $pollTimeout,
                            $taskId,
                            $taskId,
                        ),
                    ],
                ];
            }

            $remainingSeconds    = (int) ceil($deadline - microtime(true));
            $effectivePerRequest = max(1, min($remainingSeconds, self::POLL_REQUEST_TIMEOUT_SECONDS));

            $response = $client->getJson(
                '/v2/query/video_generation/' . $taskId,
                [],
                timeoutSeconds: $effectivePerRequest,
            );

            $task = is_array($response['task'] ?? null) ? $response['task'] : [];
            $status = is_string($task['status'] ?? null) ? $task['status'] : '';

            if ($status === 'succeeded') {
                if ($expectKind !== null && isset($task['task_type']) && $task['task_type'] !== $expectKind) {
                    $this->support->logger()?->warning('MiniMaxVideoTool: unexpected task_type on success', [
                        'task_id'  => $taskId,
                        'expected' => $expectKind,
                        'actual'   => $task['task_type'],
                    ]);
                }
                return ['success' => true, 'data' => $task];
            }

            if ($status === 'failed') {
                $err  = is_array($task['error'] ?? null) ? $task['error'] : [];
                $code = is_string($err['code'] ?? null) ? $err['code'] : 'unknown';
                $msg  = is_string($err['message'] ?? null) ? $err['message'] : 'video task failed';
                throw new MiniMaxApiException("MiniMax H3 task failed (code={$code}): {$msg}", 0, $task);
            }

            if ($status === 'cancelled') {
                throw new MiniMaxApiException('MiniMax H3 task was cancelled.', 0, $task);
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
     * Format a timed-out poll envelope into a failed ToolResult that
     * carries the task_id and the original submission metadata so the
     * LLM can `resume` on a subsequent turn without losing context.
     *
     * @param  array<string, mixed>       $err          poll-loop timeout envelope
     * @param  array<string, mixed>       $arguments    original call's args
     * @param  array<string, mixed>       $settings
     */
    private function timedOutResult(array $err, array $arguments, array $settings): ToolResult
    {
        $filenameRaw = isset($arguments['filename']) ? (string) $arguments['filename'] : '';
        return new ToolResult(false, $err['content'], [
            'task_id'           => $err['task_id'],
            'status'            => $err['status'],
            'timed_out'         => $err['timed_out'],
            'prompt'            => trim((string) ($arguments['prompt'] ?? '')),
            'first_frame_image' => trim((string) ($arguments['first_frame_image'] ?? '')),
            'last_frame_image'  => trim((string) ($arguments['last_frame_image'] ?? '')),
            'reference_images'  => $this->normaliseStringList($arguments['reference_images'] ?? null),
            'reference_videos'  => $this->normaliseStringList($arguments['reference_videos'] ?? null),
            'reference_audio'   => $this->normaliseStringList($arguments['reference_audio'] ?? null),
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
     * @param  array<string, mixed> $arguments
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
        array $arguments,
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

        // Ingest failures must never break the tool — fall back to the CDN URL.
        $archiveAsset = null;
        try {
            $archiveAsset = $this->mediaArchive()->ingest(new MediaIngestRequest(
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
        }

        // `archived` distinguishes the two states the trailing
        // instruction has to acknowledge — the rendered `<video>` is
        // served by the Media Archive, or it isn't.
        $archived = $archiveAsset !== null
            && $archiveAsset->asset_url !== ''
            && !str_starts_with($archiveAsset->asset_url, 'data:');
        $archiveUrl = $archived ? $archiveAsset->asset_url : null;
        $embedUrl   = $archiveUrl ?? $downloadUrl;
        $sizeNote   = $archiveUrl !== null ? '' : ' (URL valid briefly — download promptly)';

        $kind = $taskOutcome['kind'];
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
}
